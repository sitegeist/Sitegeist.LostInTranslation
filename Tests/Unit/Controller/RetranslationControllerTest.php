<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Controller;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\ContentRepository\RetranslationService;
use Sitegeist\LostInTranslation\Controller\RetranslationController;

class RetranslationControllerTest extends UnitTestCase
{
    private const PRESETS = [
        'language' => [
            'presets' => [
                'de' => ['label' => 'Deutsch', 'values' => ['de']],
                'en' => ['label' => 'English', 'values' => ['en']],
                'fr' => ['label' => 'Français', 'values' => ['fr'], 'options' => ['referenceLanguage' => 'de']],
            ],
        ],
    ];

    /**
     * @var RetranslationService|MockObject
     */
    private $retranslationService;

    private RetranslationController $controller;

    protected function setUp(): void
    {
        $presetSource = $this->createMock(ContentDimensionPresetSourceInterface::class);
        $presetSource->method('getAllPresets')->willReturn(self::PRESETS);
        $this->retranslationService = $this->createMock(RetranslationService::class);

        $this->controller = new RetranslationController($presetSource, $this->retranslationService);
        $this->inject($this->controller, 'languageDimensionName', 'language');
        $this->inject($this->controller, 'persistenceManager', $this->createMock(PersistenceManagerInterface::class));
    }

    /**
     * @test
     */
    public function metadataForALanguageWithoutReferenceLanguageDoesNotResolveTheNode(): void
    {
        $this->retranslationService->expects(self::never())->method('getContentContext');

        $result = $this->controller->getTranslationMetadataAction('some-node', 'user-jdoe', '{"language":"en"}');

        self::assertSame(['isUpToDate' => true, 'referenceLanguage' => null], \json_decode($result, true));
    }

    /**
     * @test
     */
    public function metadataForALanguageWithReferenceLanguageComparesTheModificationDates(): void
    {
        $targetContentContext = $this->createMock(ContentContext::class);
        $targetContentContext->method('getNodeByIdentifier')->willReturn($this->createMock(Node::class));
        $referenceContentContext = $this->createMock(ContentContext::class);
        $referenceContentContext->method('getNodeByIdentifier')->willReturn($this->createMock(Node::class));
        $referenceContentContext->method('getTargetDimensions')->willReturn(['language' => 'de']);
        $this->retranslationService->method('getContentContext')->willReturn($targetContentContext);
        $this->retranslationService->method('getReferenceContentContext')->willReturn($referenceContentContext);
        $this->retranslationService->method('findFirstUpdateDateOnNodeOrDescendants')
            ->willReturn(new \DateTimeImmutable('2026-08-17T13:38:07+00:00'));

        $result = $this->controller->getTranslationMetadataAction('some-node', 'user-jdoe', '{"language":"fr"}');

        self::assertSame(
            [
                'isUpToDate' => false,
                'referenceLanguage' => ['label' => 'Deutsch', 'dateModified' => '2026-08-17T13:38:07+00:00'],
            ],
            \json_decode($result, true)
        );
    }

    /**
     * @test
     */
    public function metadataForAMissingNodeInATranslatedLanguageFails(): void
    {
        $targetContentContext = $this->createMock(ContentContext::class);
        $targetContentContext->method('getNodeByIdentifier')->willReturn(null);
        $this->retranslationService->method('getContentContext')->willReturn($targetContentContext);

        $this->expectExceptionMessage('Node not found in workspace and dimension space point');

        $this->controller->getTranslationMetadataAction('some-node', 'user-jdoe', '{"language":"fr"}');
    }
}
