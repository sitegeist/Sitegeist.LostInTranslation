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
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
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
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$targetCoordinates[$this->languageDimensionName]];
        $sourceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;

        $targetContentContext = $this->retranslationService->getContentContext($workspaceName, $targetCoordinates, false);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode instanceof Node) {
            throw new \Exception('Node not found in workspace and dimension space point');
        }

        $sourceUpdateDate = null;
        $sourceLanguagePreset = null;
        if ($sourceLanguage) {
            $sourceContentContext = $this->retranslationService->getReferenceContentContext($workspaceName, $targetCoordinates);
            /** @var ?Node $sourceNode */
            $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
            if ($sourceNode instanceof Node) {
                $sourceUpdateDate = $this->retranslationService->findFirstUpdateDateOnNodeOrDescendants(
                    $sourceNode,
                    $sourceContentContext,
                    $targetContentContext
                );
            }
            $sourceLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$sourceContentContext->getTargetDimensions()[$this->languageDimensionName]];
        }

        /** otherwise, getNodeByIdentifier might register a new object ¯\_(ツ)_/¯ */
        $this->persistenceManager->clearState();

        return \json_encode(
            [
                'isUpToDate' => $sourceUpdateDate === null,
                'referenceLanguage' => $sourceLanguage
                    ? [
                        'label' => $sourceLanguagePreset ? $sourceLanguagePreset['label'] : null,
                        'dateModified' => $sourceUpdateDate?->format(\DateTime::ATOM),
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
    ): string {
        $targetCoordinates = \json_decode($targetCoordinates, true);
        $this->retranslationService->retranslateNode($nodeAggregateId, $workspaceName, $targetCoordinates);

        return \json_encode(
            [
                'message' => 'Successfully translated'
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
