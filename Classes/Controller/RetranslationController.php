<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

class RetranslationController extends ActionController
{
    /**
     * @Flow\Inject(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        protected readonly ContentContextFactory $contentContextFactory,
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly NodeTranslationService $nodeTranslationService,
    ) {
    }

    public function getTranslationMetadataAction(string $nodeAggregateId, string $workspaceName, string $coordinates): string
    {
        $targetCoordinates = \json_decode($coordinates, true);
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$targetCoordinates[$this->languageDimensionName]];
        $sourceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;

        /** @var ContentContext $targetContentContext */
        $targetContentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->contentDimensionPresetSource->findPresetsByTargetValues($targetCoordinates),
            'targetDimensions' => $targetCoordinates,
            'invisibleContentShown' => true,
        ]);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);

        $sourceNode = null;
        $sourceLanguagePreset = null;
        if ($sourceLanguage) {
            $sourceCoordinates = $targetCoordinates;
            $sourceCoordinates[$this->languageDimensionName] = $sourceLanguage;

            /** @var ContentContext $sourceContentContext */
            $sourceContentContext = $this->contentContextFactory->create([
                'workspaceName' => $workspaceName,
                'dimensions' => $this->contentDimensionPresetSource->findPresetsByTargetValues($sourceCoordinates),
                'targetDimensions' => $sourceCoordinates,
                'invisibleContentShown' => true,
            ]);
            /** @var Node $sourceNode */
            $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
            $sourceLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$sourceLanguagePreset[$this->languageDimensionName]];
        }

        return \json_encode([
            'hasReferenceLanguage' => $sourceLanguage !== null,
            'isDocument' => $targetNode->getNodeType()->isOfType('Neos.Neos:Document'),
            'isUpToDate' => false,
            'referenceLanguage' => $sourceLanguage
                ? [
                    'label' => $sourceLanguagePreset ? $sourceLanguagePreset['label'] : null,
                    'dateModified' => $sourceNode?->getLastModificationDateTime(),
                ]
                : null,
            'referenceLanguageLabel' => 'language',
            'referenceLanguageDateModified' => new \DateTimeImmutable(),
        ]);
    }

    public function translate(
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
        bool $wholeDocument,
    ): string {
        $targetCoordinates = \json_decode($targetCoordinates, true);
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$targetCoordinates[$this->languageDimensionName]];
        $sourceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;
        if ($sourceLanguage === null) {
            $this->response->setStatusCode(400);
            return \json_encode([
                'message' => 'No reference language configured for target language ' . $targetCoordinates[$this->languageDimensionName],
            ]);
        }
        $sourceCoordinates = $targetCoordinates;
        $sourceCoordinates[$this->languageDimensionName] = $sourceLanguage;

        /** @var ContentContext $sourceContentContext */
        $sourceContentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->contentDimensionPresetSource->findPresetsByTargetValues($sourceCoordinates),
            'targetDimensions' => $sourceCoordinates,
            'invisibleContentShown' => true,
        ]);

        $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$sourceNode) {
            $this->response->setStatusCode(404);
            return \json_encode([
                'message' => 'No source node found in workspace and dimension space point'
            ]);
        }

        /** @var ContentContext $targetContentContext */
        $targetContentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->contentDimensionPresetSource->findPresetsByTargetValues($targetCoordinates),
            'targetDimensions' => $targetCoordinates,
            'invisibleContentShown' => true,
        ]);

        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode) {
            $this->response->setStatusCode(404);
            return \json_encode([
                'message' => 'No target node found in workspace and dimension space point'
            ]);
        }

        if ($wholeDocument && !$targetNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            $this->response->setStatusCode(400);
            return \json_encode([
                'message' => 'Given node is not a document'
            ]);
        }

        if ($wholeDocument) {
            $this->translateDocument($sourceNode, $targetNode, $sourceContentContext, $targetContentContext);
        } else {
            $this->nodeTranslationService->translateNode($sourceNode, $targetNode, $targetContentContext);
        }

        return \json_encode([
            'message' => 'Successfully translated'
        ]);
    }

    private function translateDocument(
        NodeInterface $sourceNode,
        NodeInterface $targetNode,
        ContentContext $sourceContentContext,
        ContentContext $targetContentContext
    ): void {
        $this->nodeTranslationService->translateNode($sourceNode, $targetNode, $targetContentContext);
        foreach ($targetNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $targetChildNode) {
            $sourceChildNode = $sourceContentContext->getNodeByIdentifier($targetChildNode->getIdentifier());
            if ($sourceChildNode) {
                $this->translateDocument(
                    $sourceChildNode,
                    $targetChildNode,
                    $sourceContentContext,
                    $targetContentContext
                );
            }
        }
    }
}
