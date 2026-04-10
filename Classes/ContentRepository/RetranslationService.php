<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\Node;
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
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        protected readonly ContentDimensionPresetSourceInterface $contentDimensionPresetSource,
        protected readonly ContentContextFactory $contentContextFactory,
        protected readonly NodeTranslationService $nodeTranslationService,
    ) {
    }

    public function findFirstUpdateDateOnNodeOrDescendants(
        Node $sourceNode,
        ContentContext $sourceContext,
        ContentContext $targetContext
    ): ?\DateTimeInterface {
        /** @var ?Node $targetNode */
        $targetNode = $targetContext->getNodeByIdentifier($sourceNode->getIdentifier());
        $sourceReferenceDate = $sourceNode->getLastModificationDateTime();
        if (!$targetNode) {
            return $sourceReferenceDate;
        }
        $targetReferenceDate = $targetNode->getLastModificationDateTime();
        if ($targetReferenceDate < $sourceReferenceDate) {
            return $sourceReferenceDate;
        }

        foreach ($sourceNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            /** @var Node $sourceChildNode */
            $updateDate = $this->findFirstUpdateDateOnNodeOrDescendants($sourceChildNode, $sourceContext, $targetContext);
            if ($updateDate) {
                return $updateDate;
            }
        }

        return null;
    }

    /**
     * @param array<string,string> $targetCoordinates
     */
    public function retranslateNode(
        string $nodeAggregateId,
        string $workspaceName,
        array $targetCoordinates,
    ): void {
        $sourceContentContext = $this->getReferenceContentContext($workspaceName, $targetCoordinates);

        $sourceNode = $sourceContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$sourceNode) {
            throw new \Exception('No source node found in workspace and dimension space point');
        }

        $targetContentContext = $this->getContentContext($workspaceName, $targetCoordinates, true);
        $targetNode = $targetContentContext->getNodeByIdentifier($nodeAggregateId);
        if (!$targetNode) {
            // translation will be done implicitly here
            $targetContentContext->adoptNode($sourceNode);
        }

        $this->translateDescendants($sourceNode, $targetContentContext);
    }

    private function translateDescendants(NodeInterface $node, ContentContext $targetContentContext): void
    {
        $targetNode = $targetContentContext->getNodeByIdentifier($node->getIdentifier());
        if (!$targetNode) {
            // translation will be done implicitly here
            $targetContentContext->adoptNode($node);
        } else {
            /** @var Node $targetNode */
            if ($targetNode->getLastModificationDateTime() < $node->getLastModificationDateTime()) {
                $this->nodeTranslationService->translateNode($node, $targetNode, $targetContentContext);
            }
        }
        foreach ($node->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $this->translateDescendants($sourceChildNode, $targetContentContext);
        }
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getContentContext(string $workspaceName, array $coordinates, bool $withRemoved): ContentContext
    {
        $dimensions = [];
        foreach ($coordinates as $dimensionName => $dimensionValue) {
            $dimensions[$dimensionName] = $this->contentDimensionPresetSource->getAllPresets()[$dimensionName]['presets'][$dimensionValue]['values'];
        }

        /** @var ContentContext $contentContext */
        $contentContext = $this->contentContextFactory->create([
            'workspaceName' => $workspaceName,
            'dimensions' => $dimensions,
            'targetDimensions' => $coordinates,
            'invisibleContentShown' => true,
            'removedContentShown' => $withRemoved,
        ]);

        return $contentContext;
    }

    /**
     * @param array<string,string> $coordinates
     */
    public function getReferenceContentContext(string $workspaceName, array $coordinates): ContentContext
    {
        $targetLanguagePreset = $this->contentDimensionPresetSource->getAllPresets()[$this->languageDimensionName]['presets'][$coordinates[$this->languageDimensionName]];
        $referenceLanguage = $targetLanguagePreset['options']['referenceLanguage'] ?? null;
        if ($referenceLanguage === null) {
            throw new \Exception('No reference language configured for target language ' . $coordinates[$this->languageDimensionName]);
        }
        $referenceCoordinates = $coordinates;
        $referenceCoordinates[$this->languageDimensionName] = $referenceLanguage;

        return $this->getContentContext($workspaceName, $referenceCoordinates, false);
    }
}
