<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\Domain\Retranslator;

class RetranslationController extends ActionController
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        protected readonly Retranslator $retranslator,
    ) {
    }

    public function getTranslationMetadataAction(string $nodeAggregateId, string $workspaceName, string $contentRepositoryId, string $coordinates): string
    {
        $contentRepository = $this->contentRepositoryRegistry->get(
            ContentRepositoryId::fromString($contentRepositoryId)
        );
        $dimensionSpacePoint = DimensionSpacePoint::fromJsonString($coordinates);

        $targetSubgraph = $contentRepository->getContentGraph(WorkspaceName::fromString($workspaceName))
            ->getSubgraph(
                dimensionSpacePoint: $dimensionSpacePoint,
                visibilityConstraints: NeosVisibilityConstraints::excludeRemoved(),
            );

        $targetNode = $targetSubgraph->findNodeById(NodeAggregateId::fromString($nodeAggregateId));
        if (!$targetNode instanceof Node) {
            throw new \Exception('Node not found in workspace and dimension space point');
        }

        $sourceDimensionPoint = $this->retranslator->getReferenceDimensionSpacePoint($contentRepository, $dimensionSpacePoint);

        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $contentRepository->getContentDimensionSource()
            ->getDimension($languageDimensionId);
        $sourceLanguageValue = $languageDimension
            ->getValue($dimensionSpacePoint->getCoordinate($languageDimensionId))
            ->getConfigurationValue('options.referenceLanguage');

        $sourceLanguage = $sourceLanguageValue ? $languageDimension->getValue($sourceLanguageValue) : null;

        $staleTranslations = null;
        if ($sourceLanguage) {
            $sourceSubgraph = $contentRepository->getContentGraph(WorkspaceName::fromString($workspaceName))
                ->getSubgraph(
                    dimensionSpacePoint: $sourceDimensionPoint,
                    visibilityConstraints: NeosVisibilityConstraints::excludeRemoved(),
                );
            $sourceSubtree = $sourceSubgraph->findSubtree(
                entryNodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
                filter: FindSubtreeFilter::create(
                    nodeTypes: 'Neos.Neos:Content,Neos.Neos:ContentCollection',
                    maximumLevels: 16
                )
            );

            $staleTranslations = $contentRepository->projectionState(StaleTranslationFinder::class)
                ->findBySubtree($sourceSubtree);
        }

        return \json_encode(
            [
                'isUpToDate' => !$staleTranslations || $staleTranslations->count() === 0,
                'referenceLanguage' => $sourceLanguage
                    ? [
                        'label' => $sourceLanguage->getConfigurationValue('label'),
                        'dateModified' => null,
                    ]
                    : null,
            ],
            JSON_THROW_ON_ERROR,
        );
    }

    public function retranslateNodeAction(
        string $contentRepositoryId,
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
    ): string {
        $this->retranslator->retranslateNode(
            contentRepositoryId: ContentRepositoryId::fromString($contentRepositoryId),
            workspaceName: WorkspaceName::fromString($workspaceName),
            nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetCoordinates),
        );

        return \json_encode(
            [
                'message' => 'Successfully translated'
            ],
            JSON_THROW_ON_ERROR,
        );
    }
}
