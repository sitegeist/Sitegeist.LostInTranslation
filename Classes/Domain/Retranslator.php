<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;

#[Flow\Scope('singleton')]
class Retranslator
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly TranslationServiceInterface $translationService,
        private readonly DimensionValueDirectiveFactory $dimensionValueDirectiveFactory,
        private readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    public function retranslateNode(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): void {
        $contentRepository = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimension = $contentRepository->getContentDimensionSource()->getDimension(
            new ContentDimensionId($this->languageDimensionName)
        );

        $sourceDimensionSpacePoint = $this->getReferenceDimensionSpacePoint(
            contentRepository: $contentRepository,
            dimensionSpacePoint: $targetDimensionSpacePoint
        );

        if (!$sourceDimensionSpacePoint) {
            throw new \Exception('No source dimension space point found for retranslation');
        }

        $sourceSubgraph = $contentRepository->getContentGraph($workspaceName)
            ->getSubgraph(
                $sourceDimensionSpacePoint,
                NeosVisibilityConstraints::excludeRemoved(),
            );

        $targetSubgraph = $contentRepository->getContentGraph($workspaceName)
            ->getSubgraph(
                $targetDimensionSpacePoint,
                VisibilityConstraints::createEmpty(),
            );

        $sourceSubtree = $sourceSubgraph->findSubtree(
            entryNodeAggregateId: $nodeAggregateId,
            filter: FindSubtreeFilter::create(
                nodeTypes: 'Neos.Neos:Content,Neos.Neos:ContentCollection',
                maximumLevels: 16
            )
        );

        if (!$sourceSubtree) {
            throw new \Exception('No source node found in workspace and dimension space point');
        }

        $texts = [];
        foreach (
            $contentRepository->projectionState(StaleTranslationFinder::class)
                ->findBySubtree($sourceSubtree) as $staleTranslation
        ) {
            $node = $this->findNodeInSubtree($staleTranslation->nodeAggregateId, $sourceSubtree);
            if ($node) {
                foreach ($staleTranslation->propertyNames as $propertyName) {
                    $propertyValue = $node->getProperty($propertyName);
                    if (is_string($propertyValue)) {
                        $texts[$nodeAggregateId->value . ':' . $propertyName->value] = $propertyValue;
                    }
                }
            }
        }

        $targetLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        );
        $sourceLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        );
        $sourceDeeplLanguage = $sourceLanguageDirective?->deeplSourceId;
        $targetDeeplLanguage = $targetLanguageDirective?->deeplTargetId;

        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return;
        }
        $translatedTexts = $this->translationService->translate(
            $texts,
            $targetDeeplLanguage,
            $sourceDeeplLanguage,
        );

        $propertiesToWriteByNodeAggregateId = [];
        foreach ($translatedTexts as $id => $text) {
            [$nodeAggregateId, $propertyName] = explode(':', $id);
            $propertiesToWriteByNodeAggregateId[$nodeAggregateId][$propertyName] = $text;
        }

        foreach ($propertiesToWriteByNodeAggregateId as $nodeAggregateId => $propertiesToWrite) {
            $nodeAggregateId = NodeAggregateId::fromString($nodeAggregateId);
            $targetNode = $targetSubgraph->findNodeById($nodeAggregateId);
            if ($targetNode) {
                $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
                $contentRepository->handle(SetNodeProperties::create(
                    workspaceName: $workspaceName,
                    nodeAggregateId: $nodeAggregateId,
                    originDimensionSpacePoint: OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
                    propertyValues: PropertyValuesToWrite::fromArray($propertiesToWrite),
                ));
                $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
            } else {
                $contentRepository->handle(CreateNodeVariant::create(
                    workspaceName: $workspaceName,
                    nodeAggregateId: $nodeAggregateId,
                    sourceOrigin: $this->findNodeInSubtree($nodeAggregateId, $sourceSubtree)->originDimensionSpacePoint,
                    targetOrigin: OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
                ));
            }
        }
    }

    private function findNodeInSubtree(NodeAggregateId $nodeAggregateId, Subtree $subtree): ?Node
    {
        if ($subtree->node->aggregateId->equals($nodeAggregateId)) {
            return $subtree->node;
        }

        foreach ($subtree->children as $childSubtree) {
            $node = $this->findNodeInSubtree($nodeAggregateId, $childSubtree);
            if ($node) {
                return $node;
            }
        }

        return null;
    }

    public function getReferenceDimensionSpacePoint(
        ContentRepository $contentRepository,
        DimensionSpacePoint $dimensionSpacePoint
    ): ?DimensionSpacePoint {
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $contentRepository->getContentDimensionSource()
            ->getDimension($languageDimensionId);
        $sourceLanguageValue = $languageDimension
            ->getValue($dimensionSpacePoint->getCoordinate($languageDimensionId))
            ->getConfigurationValue('options.referenceLanguage');

        $sourceLanguage = $sourceLanguageValue ? $languageDimension->getValue($sourceLanguageValue) : null;

        if ($sourceLanguage) {
            $sourceCoordinates = $dimensionSpacePoint->coordinates;
            $sourceCoordinates[$this->languageDimensionName] = $sourceLanguageValue;
            return DimensionSpacePoint::fromArray($sourceCoordinates);
        }

        return null;
    }
}
