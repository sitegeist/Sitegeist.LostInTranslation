<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Service\ContentContext;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Neos\Neos\Domain\Service\ContentDimensionPresetSourceInterface;
use Sitegeist\LostInTranslation\Domain\Retranslation\Discrepancy;
use Sitegeist\LostInTranslation\Domain\Retranslation\DiscrepancyCollection;
use Sitegeist\LostInTranslation\Domain\Retranslation\InterdimensionalModificationComparisonResult;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;

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

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.removeNodesWithoutSource")
     * @var bool
     */
    protected $removeNodesWithoutSource;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeUntranslatedProperties")
     * @var bool
     */
    protected $synchronizeUntranslatedProperties;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodePosition")
     * @var bool
     */
    protected $synchronizeNodePosition;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodeVisibility")
     * @var bool
     */
    protected $synchronizeNodeVisibility;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.retranslation.synchronizeNodeType")
     * @var bool
     */
    protected $synchronizeNodeType;

    /**
     * @Flow\Inject
     * @var TranslatablePropertyNamesFactory
     */
    protected $translatablePropertiesFactory;

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
    ): InterdimensionalModificationComparisonResult {
        /** @var ?Node $targetNode */
        $targetNode = $targetContext->getNodeByIdentifier($sourceNode->getIdentifier());
        $sourceReferenceDate = $sourceNode->getLastModificationDateTime();
        $comparisonResult = new InterdimensionalModificationComparisonResult(
            $sourceNode,
            $targetNode
        );
        if (!$targetNode) {
            $comparisonResult->addOneDiscrepancy(
                new Discrepancy(
                    $sourceNode,
                    null,
                    $sourceReferenceDate,
                    'Target node is missing'
                )
            );
            return $comparisonResult;
        }

        $targetReferenceDate = $targetNode->getLastModificationDateTime();
        if ($targetReferenceDate < $sourceReferenceDate) {
            $comparisonResult->addOneDiscrepancy(
                new Discrepancy(
                    $sourceNode,
                    $targetNode,
                    $sourceReferenceDate,
                    'Source node is more current than target node'
                )
            );
        }

        /**
         * @var array<string, NodeInterface> $sourceNodeByIdentifier
         */
        $sourceNodeByIdentifier = [];
        foreach ($sourceNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $sourceNodeByIdentifier[$sourceChildNode->getIdentifier()] = $sourceChildNode;
            /** @var Node $sourceChildNode */
            $subResult = $this->findFirstUpdateDateOnNodeOrDescendants($sourceChildNode, $sourceContext, $targetContext);
            if ($subResult->hasDiscrepancies()) {
                $comparisonResult->addSubResult($subResult);
            }
        }

        foreach ($targetNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $targetChildNode) {
            if (array_key_exists($targetChildNode->getIdentifier(), $sourceNodeByIdentifier)) {
                $sourceNode = $sourceNodeByIdentifier[$targetChildNode->getIdentifier()];
                if ($targetChildNode->getIndex() !== $sourceNode->getIndex()) {
                    $comparisonResult->addOneDiscrepancy(
                        new Discrepancy(
                            $sourceNode,
                            $targetNode,
                            $targetChildNode->getLastModificationDateTime(),
                            'Source node has been moved'
                        )
                    );
                }
            } else {
                // we do not know the date of the deletion so we decide it was just now
                // this will be improved for neos 9
                $comparisonResult->addOneDiscrepancy(
                    new Discrepancy(
                        $sourceNode,
                        $targetNode,
                        new \DateTimeImmutable(),
                        'Source node has been deleted'
                    )
                );            }
        }

        return $comparisonResult;
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
            /** @var Node $node */
            /** @var Node $targetNode */
            if ($targetNode->getLastModificationDateTime() < $node->getLastModificationDateTime()) {
                $this->nodeTranslationService->translateNode($node, $targetNode, $targetContentContext);
                if ($this->synchronizeNodeType && $node->getNodeType()->getName() !== $targetNode->getNodeType()->getName()) {
                    $targetNode->setNodeType($node->getNodeType());
                }
                if ($this->synchronizeUntranslatedProperties) {
                    $translatableProperties = $this->translatablePropertiesFactory->createForNodeType($node->getNodeType());
                    $sourceProperties = $node->getProperties();
                    $targetProperties = $targetNode->getProperties();
                    // set properties as in the source if no translation is configured
                    foreach ($sourceProperties as $propertyName => $value) {
                        if (!$translatableProperties->isTranslatable($propertyName) && $targetProperties[ $propertyName ] !== $value) {
                            $targetNode->setProperty($propertyName, $value);
                        }
                    }
                    // remove properties that are not present in the source
                    foreach ($targetProperties as $propertyName => $value) {
                        if ($sourceProperties->offsetExists($propertyName) === false) {
                            $targetNode->removeProperty($propertyName);
                        }
                    }
                }

                if ($this->synchronizeNodeVisibility) {
                    // sync visibility properties
                    if ($node->isHidden() !== $targetNode->isHidden()) {
                        $targetNode->setHidden($node->isHidden());
                    }
                    if ($node->getHiddenBeforeDateTime() !== $targetNode->getHiddenBeforeDateTime()) {
                        $targetNode->setHiddenBeforeDateTime($node->getHiddenBeforeDateTime());
                    }
                    if ($node->getHiddenAfterDateTime() !== $targetNode->getHiddenAfterDateTime()) {
                        $targetNode->setHiddenAfterDateTime($node->getHiddenAfterDateTime());
                    }
                }
            }

            // sync node position
            if ($this->synchronizeNodePosition && $node->getIndex() !== $targetNode->getIndex()) {
                $targetNode->setIndex($node->getIndex());
            }
        }

        /**
         * @var array<string, NodeInterface> $sourceNodeByIdentifier
         */
        $sourceNodeByIdentifier = [];
        foreach ($node->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $sourceChildNode) {
            $sourceNodeByIdentifier[$sourceChildNode->getIdentifier()] = $sourceChildNode;
            $this->translateDescendants($sourceChildNode, $targetContentContext);
        }

        if ($this->removeNodesWithoutSource) {
            if ($targetNode instanceof NodeInterface) {
                foreach ($targetNode->getChildNodes('Neos.Neos:Content,Neos.Neos:ContentCollection') as $targetChildNode) {
                    if (!array_key_exists($targetChildNode->getIdentifier(), $sourceNodeByIdentifier)) {
                        $targetChildNode->remove();
                    }
                }
            }
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
