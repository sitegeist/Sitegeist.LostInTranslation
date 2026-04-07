<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Sitegeist\LostInTranslation\ContentRepository\RetranslationService;

class RetranslationController extends ActionController
{
    /**
     * @Flow\Inject(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly RetranslationService $retranslationService,
    ) {
    }

    public function getTranslationMetadataAction(string $nodeAggregateId, string $workspaceName, string $coordinates): string
    {
        $targetCoordinates = \json_decode($coordinates, true);
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$targetCoordinates[$this->languageDimensionName]];
        $sourceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;

        $targetContentContext = $this->retranslationService->getContentContext($workspaceName, $targetCoordinates);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode instanceof Node) {
            throw new \Exception('Node not found in workspace and dimension space point');
        }

        $sourceNode = null;
        $sourceLanguagePreset = null;
        if ($sourceLanguage) {
            $sourceContentContext = $this->retranslationService->getReferenceContentContext($sourceLanguage, $targetCoordinates);
            /** @var ?Node $sourceNode */
            $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
            $sourceLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$sourceContentContext->getTargetDimensions()[$this->languageDimensionName]];
        }

        return \json_encode(
            [
                'isDocument' => $targetNode->getNodeType()->isOfType('Neos.Neos:Document') ?: false,
                'isUpToDate' => !$sourceNode || $sourceNode->getLastModificationDateTime() <= $targetNode->getLastModificationDateTime(),
                'referenceLanguage' => $sourceLanguage
                    ? [
                        'label' => $sourceLanguagePreset ? $sourceLanguagePreset['label'] : null,
                        'dateModified' => $sourceNode?->getLastModificationDateTime(),
                    ]
                    : null,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    public function retranslateNodeAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
        bool $wholeDocument,
    ): string {
        $targetCoordinates = \json_decode($targetCoordinates, true);
        if ($wholeDocument) {
            $this->retranslationService->retranslateDocument($nodeAggregateId, $workspaceName, $targetCoordinates);
        } else {
            $this->retranslationService->retranslateContent($nodeAggregateId, $workspaceName, $targetCoordinates);
        }

        return \json_encode(
            [
                'message' => 'Successfully translated'
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
