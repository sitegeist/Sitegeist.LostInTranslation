<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\SerializedPropertyValues;
use Neos\ContentRepository\Core\Infrastructure\Property\PropertyConverter;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\NodeTags;
use Neos\ContentRepository\Core\Projection\ContentGraph\PropertyCollection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Timestamps;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateClassification;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirective;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Domain\PostProcessor\TranslatedPropertyPostProcessorInterface;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Symfony\Component\Serializer\Serializer;

/**
 * Covers the `nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation` setting, i.e. the only behaviour of
 * {@see StalePropertyCommandBuilder::buildFromCollectedProperties()} that a global flag switches.
 *
 * The interesting part is not *that* `html_entity_decode` runs, but *where* in the pipeline: on the deflated map, so
 * after DeepL returned but before values are enflated for connectors and before per-property post-processors see them.
 * An entity decoding into `&`, `"` or a non-breaking space therefore changes what those downstream steps receive —
 * which is what the ordering tests below pin down.
 */
class StalePropertyCommandBuilderTest extends UnitTestCase
{
    private StalePropertyCommandBuilder $builder;

    private TranslationServiceInterface|MockObject $translationService;

    private Serializer|MockObject $serializer;

    public function setUp(): void
    {
        $this->builder = new StalePropertyCommandBuilder();
        $this->translationService = $this->createMock(TranslationServiceInterface::class);
        $this->inject($this->builder, 'translationService', $this->translationService);
        $this->serializer = $this->getMockBuilder(Serializer::class)->disableOriginalConstructor()->getMock();
    }

    /** @test */
    public function htmlEntitiesInTheTranslationArePreservedWhileTheFlagIsOff(): void
    {
        $this->applyHtmlEntityDecode(false);
        $this->translationService->method('translate')->willReturn(['title' => 'Fisch &amp; Chips']);

        $command = $this->build(
            propertiesToTranslate: ['title' => 'Fish & Chips'],
            translatablePropertyNames: $this->translatable('title'),
        );

        self::assertSame(['title' => 'Fisch &amp; Chips'], $command?->propertyValues->values);
    }

    /** @test */
    public function htmlEntitiesInTheTranslationAreDecodedWhileTheFlagIsOn(): void
    {
        $this->applyHtmlEntityDecode(true);
        $this->translationService->method('translate')->willReturn([
            'title' => 'Fisch &amp; Chips',
            'subtitle' => '&quot;lecker&quot;',
            'teaser' => 'Fisch&nbsp;&amp;&nbsp;Chips',
        ]);

        $command = $this->build(
            propertiesToTranslate: ['title' => 'Fish & Chips', 'subtitle' => '"tasty"', 'teaser' => 'Fish & Chips'],
            translatablePropertyNames: $this->translatable('title', 'subtitle', 'teaser'),
        );

        self::assertSame(
            [
                'title' => 'Fisch & Chips',
                'subtitle' => '"lecker"',
                'teaser' => "Fisch\u{00A0}&\u{00A0}Chips",
            ],
            $command?->propertyValues->values,
        );
    }

    /**
     * Decoding happens before post-processing, so a `uriPathSegment` whose translation carries an entity is handed to
     * the post-processor already decoded — and the decoded `&` is what makes the slug invalid and triggers a rewrite.
     *
     * @test
     */
    public function theDecodedValueIsWhatThePostProcessorReceives(): void
    {
        $this->applyHtmlEntityDecode(true);
        $this->translationService->method('translate')->willReturn(['uriPathSegment' => 'fisch-&amp;-chips']);

        $postProcessor = $this->createMock(TranslatedPropertyPostProcessorInterface::class);
        $postProcessor->expects(self::once())
            ->method('process')
            ->with('fisch-&-chips')
            ->willReturn('fisch-und-chips');

        $command = $this->build(
            propertiesToTranslate: ['uriPathSegment' => 'fish-and-chips'],
            translatablePropertyNames: TranslatablePropertyNames::fromArray([
                new TranslatablePropertyName(PropertyName::fromString('uriPathSegment'), null, $postProcessor),
            ]),
        );

        self::assertSame(['uriPathSegment' => 'fisch-und-chips'], $command?->propertyValues->values);
    }

    /** @test */
    public function theRawValueIsWhatThePostProcessorReceivesWhileTheFlagIsOff(): void
    {
        $this->applyHtmlEntityDecode(false);
        $this->translationService->method('translate')->willReturn(['uriPathSegment' => 'fisch-&amp;-chips']);

        $postProcessor = $this->createMock(TranslatedPropertyPostProcessorInterface::class);
        $postProcessor->expects(self::once())
            ->method('process')
            ->with('fisch-&amp;-chips')
            ->willReturn('fisch-amp-chips');

        $command = $this->build(
            propertiesToTranslate: ['uriPathSegment' => 'fish-and-chips'],
            translatablePropertyNames: TranslatablePropertyNames::fromArray([
                new TranslatablePropertyName(PropertyName::fromString('uriPathSegment'), null, $postProcessor),
            ]),
        );

        self::assertSame(['uriPathSegment' => 'fisch-amp-chips'], $command?->propertyValues->values);
    }

    /**
     * Decoding runs on the deflated map, i.e. on every leaf of a nested (connector-extracted) property, and before the
     * leaves are enflated again — so the connector is handed decoded translations.
     *
     * @test
     */
    public function everyLeafOfANestedPropertyIsDecoded(): void
    {
        $this->applyHtmlEntityDecode(true);
        $this->translationService->method('translate')->willReturn([
            'teaser.headline' => 'Fisch &amp; Chips',
            'teaser.text' => '&quot;lecker&quot;',
        ]);

        $sourceValue = new \stdClass();
        $translatedValue = new \stdClass();
        $connector = $this->createMock(TranslationConnectorInterface::class);
        $connector->expects(self::once())
            ->method('applyTranslations')
            ->with($sourceValue, ['headline' => 'Fisch & Chips', 'text' => '"lecker"'])
            ->willReturn($translatedValue);

        $command = $this->build(
            propertiesToTranslate: ['teaser' => ['headline' => 'Fish & Chips', 'text' => '"tasty"']],
            translatablePropertyNames: TranslatablePropertyNames::fromArray([
                new TranslatablePropertyName(PropertyName::fromString('teaser'), $connector),
            ]),
            sourceNode: $this->sourceNode(
                SerializedPropertyValues::fromArray([
                    'teaser' => ['value' => ['headline' => 'Fish & Chips'], 'type' => \stdClass::class],
                ]),
                $sourceValue,
            ),
        );

        self::assertSame(['teaser' => $translatedValue], $command?->propertyValues->values);
    }

    /**
     * Values decided without translating — a blanked source propagated verbatim to mirror a clearing — never reach the
     * decode step, because it only ever touches what came back from DeepL.
     *
     * @test
     */
    public function valuesThatWereNotTranslatedAreNotDecoded(): void
    {
        $this->applyHtmlEntityDecode(true);
        $this->translationService->expects(self::never())->method('translate');

        $command = $this->build(
            propertiesToTranslate: [],
            translatablePropertyNames: $this->translatable('title'),
            propertiesToSet: ['title' => '&amp;'],
        );

        self::assertSame(['title' => '&amp;'], $command?->propertyValues->values);
    }

    private function applyHtmlEntityDecode(bool $enabled): void
    {
        $this->inject($this->builder, 'experimentalApplyHtmlEntityDecodeAfterTranslation', $enabled);
    }

    /**
     * @param array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate
     * @param array<non-empty-string, string> $propertiesToSet
     */
    private function build(
        array $propertiesToTranslate,
        TranslatablePropertyNames $translatablePropertyNames,
        array $propertiesToSet = [],
        ?Node $sourceNode = null,
    ): ?SetNodeProperties {
        return $this->builder->buildFromCollectedProperties(
            directive: new NodeTypeTranslationDirective(true, $translatablePropertyNames),
            sourceNode: $sourceNode ?? $this->sourceNode(SerializedPropertyValues::createEmpty()),
            propertiesToTranslate: $propertiesToTranslate,
            propertiesToSet: $propertiesToSet,
            sourceDeeplLanguage: 'EN',
            targetDeeplLanguage: 'DE',
            targetWorkspaceName: WorkspaceName::forLive(),
            targetOrigin: OriginDimensionSpacePoint::fromArray(['language' => 'de']),
        );
    }

    private function translatable(string ...$propertyNames): TranslatablePropertyNames
    {
        return TranslatablePropertyNames::fromArray(array_map(
            static fn (string $name) => new TranslatablePropertyName(PropertyName::fromString($name)),
            $propertyNames,
        ));
    }

    /**
     * `$deserializedValue`, when given, is what `$sourceNode->getProperty()` yields for every serialized property —
     * enough for the connector path, which only needs *an* object to hand to the connector.
     */
    private function sourceNode(
        SerializedPropertyValues $serializedProperties,
        ?object $deserializedValue = null,
    ): Node {
        if ($deserializedValue !== null) {
            $this->serializer->method('denormalize')->willReturn($deserializedValue);
        }

        return Node::create(
            ContentRepositoryId::fromString('default'),
            WorkspaceName::forLive(),
            DimensionSpacePoint::fromArray(['language' => 'en']),
            NodeAggregateId::fromString('source-node'),
            OriginDimensionSpacePoint::fromArray(['language' => 'en']),
            NodeAggregateClassification::CLASSIFICATION_REGULAR,
            NodeTypeName::fromString('Sitegeist.LostInTranslation:Document'),
            new PropertyCollection($serializedProperties, new PropertyConverter($this->serializer)),
            null,
            NodeTags::createEmpty(),
            Timestamps::create(
                new \DateTimeImmutable('@0'),
                new \DateTimeImmutable('@0'),
                null,
                null,
            ),
            VisibilityConstraints::createEmpty(),
        );
    }
}
