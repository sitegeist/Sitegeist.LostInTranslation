<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\Dimension\ConfigurationBasedContentDimensionSource;
use Neos\ContentRepository\Core\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\CommandHook\DisabledCommandHook;
use Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHookFactory;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * The hooks are registered on the `default` content repository preset, so they are built for EVERY content repository
 * using that preset — including ones without the configured language dimension. What the factory does in that case
 * decides whether such a CR is merely untranslated or entirely unbuildable.
 */
class SynchronizationCommandHookFactoryTest extends UnitTestCase
{
    /**
     * @return array<int,array<string,string>>
     */
    private function oneRule(): array
    {
        return [[
            'sourceWorkspaceName' => 'live',
            'sourceDimension' => 'en',
            'targetWorkspaceName' => 'live',
            'targetDimension' => 'de',
            'scope' => 'Document',
        ]];
    }

    /**
     * @param array<int,array<string,string>> $synchronization
     */
    private function factory(bool $enabled, array $synchronization): SynchronizationCommandHookFactory
    {
        $factory = new SynchronizationCommandHookFactory(
            (new \ReflectionClass(ContentRepositoryRegistry::class))->newInstanceWithoutConstructor(),
            new StalePropertyCommandBuilder(),
            $this->createMock(TranslationServiceInterface::class),
            new AISystemTranslationRuntimeState(),
        );
        $factory->enabled = $enabled;
        $factory->languageDimensionName = 'language';
        $factory->synchronization = $synchronization;
        return $factory;
    }

    private function dependenciesWithoutLanguageDimension(): CommandHooksFactoryDependencies
    {
        $contentDimensionSource = new ConfigurationBasedContentDimensionSource([]);
        return CommandHooksFactoryDependencies::create(
            ContentRepositoryId::fromString('default'),
            $this->createMock(ContentGraphReadModelInterface::class),
            NodeTypeManager::createFromArrayConfiguration([]),
            $contentDimensionSource,
            new InterDimensionalVariationGraph(
                $contentDimensionSource,
                new ContentDimensionZookeeper($contentDimensionSource),
            ),
        );
    }

    /** @test */
    public function buildReturnsADisabledHookWhenTranslationIsOffAndTheLanguageDimensionIsMissing(): void
    {
        $hook = $this->factory(false, $this->oneRule())->build($this->dependenciesWithoutLanguageDimension());

        self::assertInstanceOf(DisabledCommandHook::class, $hook);
    }

    /** @test */
    public function buildReturnsADisabledHookWhenNoRulesAreConfiguredAndTheLanguageDimensionIsMissing(): void
    {
        // `synchronization: []` is the shipped default — the feature is off unless rules are added.
        $hook = $this->factory(true, [])->build($this->dependenciesWithoutLanguageDimension());

        self::assertInstanceOf(DisabledCommandHook::class, $hook);
    }

    /** @test */
    public function buildThrowsWhenRulesAreConfiguredButTheLanguageDimensionIsMissing(): void
    {
        // Configured to synchronize, yet the dimension it would synchronize across does not exist: a misconfiguration
        // that must not be swallowed.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1779052300);

        $this->factory(true, $this->oneRule())->build($this->dependenciesWithoutLanguageDimension());
    }
}
