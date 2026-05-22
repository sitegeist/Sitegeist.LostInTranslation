<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\ReferenceDimensionSpacePointResolver;
use Sitegeist\LostInTranslation\Domain\Retranslator;

/**
 * HTTP entry point for the inspector "Retranslate" view in the Neos UI.
 *
 * Two endpoints, both returning JSON:
 *  - `getTranslationMetadata` reports whether the target-language subtree is in sync with its
 *    source (reference) language. "In sync" is sourced from the {@see StaleTranslationProjection}:
 *    if no stale records exist below the node at the target origin, the UI shows the up-to-date
 *    state.
 *  - `retranslateNode` delegates to {@see Retranslator} (same path as the CLI command).
 *
 * The reference language is derived from the target preset's `referenceLanguage` configuration via
 * {@see ReferenceDimensionSpacePointResolver}; if the target has no reference language configured
 * (e.g. the source language itself) the UI is told there's nothing to compare against.
 */
class RetranslationController extends ActionController
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected Retranslator $retranslator;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    /**
     * Report whether translations for the given node into the target dimension are up to date.
     *
     * The "stale" signal is whatever {@see StaleTranslationProjection} has recorded for the
     * target origin DSP — no source/target timestamp comparison happens here.
     */
    public function getTranslationMetadataAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $coordinates,
        string $contentRepositoryId = 'default',
    ): string {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepositoryId));
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return $this->jsonResponse([
                'isUpToDate' => true,
                'referenceLanguage' => null,
                'staleNodeCount' => 0,
            ]);
        }

        /** @var array<string, string> $targetCoordinates */
        $targetCoordinates = \json_decode($coordinates, true, flags: JSON_THROW_ON_ERROR);
        $targetDimensionSpacePoint = DimensionSpacePoint::fromArray($targetCoordinates);

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($sourceDimensionSpacePoint === null) {
            // No referenceLanguage on the target preset → nothing to compare against.
            return $this->jsonResponse([
                'isUpToDate' => true,
                'referenceLanguage' => null,
                'staleNodeCount' => 0,
            ]);
        }

        $sourceLanguageValue = $sourceDimensionSpacePoint->coordinates[$this->languageDimensionName];
        $sourceLanguageDimensionValue = $languageDimension->getValue($sourceLanguageValue);
        $configuredLabel = $sourceLanguageDimensionValue?->configuration['label'] ?? null;
        $referenceLabel = is_string($configuredLabel) ? $configuredLabel : $sourceLanguageValue;

        $contentGraph = $cr->getContentGraph(WorkspaceName::fromString($workspaceName));
        $sourceSubgraph = $contentGraph->getSubgraph(
            $sourceDimensionSpacePoint,
            NeosVisibilityConstraints::excludeRemoved(),
        );
        $sourceSubtree = $sourceSubgraph->findSubtree(
            NodeAggregateId::fromString($nodeAggregateId),
            FindSubtreeFilter::create(
                nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                    NodeTypeNames::fromStringArray(['Neos.Neos:ContentCollection', 'Neos.Neos:Content'])
                ),
            ),
        );
        if ($sourceSubtree === null) {
            return $this->jsonResponse([
                'isUpToDate' => true,
                'referenceLanguage' => ['label' => $referenceLabel],
                'staleNodeCount' => 0,
            ]);
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
            ->staleTranslationFinder
            ->findBySubtree($sourceSubtree, $targetOrigin);
        $staleCount = count($staleTranslations->items);

        return $this->jsonResponse([
            'isUpToDate' => $staleCount === 0,
            'referenceLanguage' => ['label' => $referenceLabel],
            'staleNodeCount' => $staleCount,
        ]);
    }

    /**
     * Trigger {@see Retranslator::retranslateNode()} for the given node into the target dimension.
     */
    public function retranslateNodeAction(
        string $nodeAggregateId,
        string $workspaceName,
        string $targetCoordinates,
        string $contentRepositoryId = 'default',
    ): string {
        /** @var array<string, string> $coordinatesArray */
        $coordinatesArray = \json_decode($targetCoordinates, true, flags: JSON_THROW_ON_ERROR);
        $result = $this->retranslator->retranslateNode(
            ContentRepositoryId::fromString($contentRepositoryId),
            WorkspaceName::fromString($workspaceName),
            NodeAggregateId::fromString($nodeAggregateId),
            DimensionSpacePoint::fromArray($coordinatesArray),
        );

        return $this->jsonResponse([
            'message' => $result->skippedReason !== null
                ? sprintf('Skipped: %s', $result->skippedReason)
                : ($result->isNoOp() ? 'No-op: nothing to retranslate' : 'Successfully translated'),
            'stalePropertyCommandsDispatched' => $result->stalePropertyCommandsDispatched,
            'variantCommandsDispatched' => $result->variantCommandsDispatched,
            'skippedReason' => $result->skippedReason,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setContentType('application/json');
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }
}
