<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;

/**
 * @Flow\Scope("singleton")
 */
class RetranslationService
{
    /**
     * @Flow\Inject(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly ContentContextFactory $contentContextFactory,
        protected readonly NodeTranslationService $nodeTranslationService,
    ) {
    }

    /**
     * @param array<string,string> $targetCoordinates
     */
    public function retranslateContent(
        string $nodeAggregateId,
        string $workspaceName,
        array $targetCoordinates,
    ): void {
        $sourceContentContext = $this->getReferenceContentContext($workspaceName, $targetCoordinates);
        $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$sourceNode instanceof NodeInterface) {
            throw new \Exception('No source node found in workspace and dimension space point');
        }
        $targetContentContext = $this->getContentContext($workspaceName, $targetCoordinates);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode instanceof NodeInterface) {
            $targetNode = $targetContentContext->adoptNode($sourceNode);
        }
        $this->nodeTranslationService->translateNode($sourceNode, $targetNode, $targetContentContext);
    }

    /**
     * @param array<string,string> $targetCoordinates
     */
    public function retranslateDocument(
        string $nodeAggregateId,
        string $workspaceName,
        array $targetCoordinates,
    ): void {
        $sourceContentContext = $this->getReferenceContentContext($workspaceName, $targetCoordinates);

        $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$sourceNode) {
            throw new \Exception('No source node found in workspace and dimension space point');
        }
        if (!$sourceNode->getNodeType()->isOfType('Neos.Neos:Document')) {
            throw new \Exception('Given node is not a document');
        }

        $targetContentContext = $this->getContentContext($workspaceName, $targetCoordinates);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode) {
            $targetContentContext->adoptNode($sourceNode);
        } else {
            $this->removeUntetheredDescendants($targetNode);
        }

        $this->translateDescendants($sourceNode, $targetContentContext);
    }

    private function removeUntetheredDescendants(NodeInterface $node): void
    {
        foreach ($node->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $childNode) {
            if (!$childNode->isAutoCreated()) {
                $childNode->remove();
            } else {
                $this->removeUntetheredDescendants($childNode);
            }
        }
    }

    private function translateDescendants(NodeInterface $node, ContentContext $targetContentContext): void
    {
        $targetNode = $targetContentContext->getNodeByIdentifier($node->getIdentifier());
        if (!$targetNode) {
            $targetNode = $targetContentContext->adoptNode($node);
        }
        $this->nodeTranslationService->translateNode($node, $targetNode, $targetContentContext);
        foreach ($node->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $this->translateDescendants($sourceChildNode, $targetContentContext);
        }
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getContentContext(string $workspaceName, array $coordinates): ContentContext
    {
        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $this->contentDimensionPresetSource->findPresetsByTargetValues($coordinates),
            'targetDimensions' => $coordinates,
            'invisibleContentShown' => true,
        ]);

        return $contentContext;
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getReferenceContentContext(string $workspaceName, array $coordinates): ContentContext
    {
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName][$coordinates[$this->languageDimensionName]];
        $referenceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;
        if ($referenceLanguage === null) {
            throw new \Exception('No reference language configured for target language ' . $coordinates[$this->languageDimensionName]);
        }
        $referenceCoordinates = $coordinates;
        $referenceCoordinates[$this->languageDimensionName] = $referenceLanguage;

        return $this->getContentContext($workspaceName, $referenceCoordinates);
    }
}
