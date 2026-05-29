<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;

/**
 * Walks the entire source-dimension subgraph from each root aggregate downward and emits translation commands for every
 * translatable node — independent of the stale-translation projection. Where {@see WorkspaceSynchronizer} only acts on
 * records the projection has already flagged, this service treats every node in the source dimension as a candidate.
 *
 * Decision matrix per node (only when `directive->enabled`):
 *   - target variant absent + non-tethered → `CreateNodeVariant`; the
 *     {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} cascades a
 *     full-properties `SetNodeProperties` automatically.
 *   - target variant absent + tethered → skip; the tethered variant is created (and translated) by its non-tethered
 *     ancestor's `CreateNodeVariant` cascade.
 *   - target variant present + `$skipExisting` + no stale row → skip (keep manual edits).
 *   - target variant present otherwise (i.e. `!$skipExisting`, OR a stale record exists) → translated
 *     `SetNodeProperties` for *every* translatable property. This applies to tethered children too: once their variant
 *     exists, a property change on the source is refreshed directly. The stale-record case is the load-bearing
 *     override: a stale node is always refreshed even under `$skipExisting`.
 *
 * Sole entry point {@see self::synchronizeWorkspaceFull} (CLI `synchronize --full`) dispatches inline, delegating
 * per-node decisions to {@see self::decideCommandForNode} and traversal to {@see self::traverseSourceSubtrees}.
 *
 * TODO(cross-workspace): drop the `sourceWorkspace == targetWorkspace` constraint to support workflows like a full
 * re-translation from published `live` into `de-review` without an intermediate publish.
 */
class FullWorkspaceSynchronizer
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected StalePropertyCommandBuilder $stalePropertyCommandBuilder;

    #[Flow\Inject]
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    public function synchronizeWorkspaceFull(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $skipExisting = true,
        bool $useCache = true,
        bool $dryRun = false,
    ): WorkspaceSynchronizationResult {
        // TODO(cross-workspace): see class docblock.
        if (!$sourceWorkspaceName->equals($targetWorkspaceName)) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'cross-workspace full synchronization is not yet supported (source "%s" != target "%s")',
                $sourceWorkspaceName->value,
                $targetWorkspaceName->value,
            ));
        }

        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'language dimension "%s" not configured in CR "%s"',
                $this->languageDimensionName,
                $contentRepositoryId->value,
            ));
        }

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $expectedSourceDsp = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($expectedSourceDsp === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'no referenceLanguage configured for target dimension %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }
        if (!$expectedSourceDsp->equals($sourceDimensionSpacePoint)) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'source dimension %s does not match configured referenceLanguage %s for target dimension %s',
                $sourceDimensionSpacePoint->toJson(),
                $expectedSourceDsp->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDeepl = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        )?->deeplSourceId;
        $targetDeepl = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        )?->deeplTargetId;
        if ($sourceDeepl === null || $targetDeepl === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $contentGraph = $cr->getContentGraph($targetWorkspaceName);
        $staleByNodeId = $this->collectStaleByNodeId($cr, $contentGraph, $targetWorkspaceName, $targetOrigin);
        $nodeTypeManager = $cr->getNodeTypeManager();

        $sourceSubgraph = $contentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $contentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $perNodeResults = [];
        foreach ($this->traverseSourceSubtrees($contentGraph, $sourceSubgraph) as $node) {
            $command = $this->decideCommandForNode(
                $nodeTypeManager,
                $node,
                $targetSubgraph,
                $targetOrigin,
                $staleByNodeId,
                $sourceDeepl,
                $targetDeepl,
                $skipExisting,
                $useCache,
            );
            if ($command === null) {
                continue;
            }
            $isVariant = $command instanceof CreateNodeVariant;
            $perNodeResults[] = new PerNodeSynchronizationResult(
                $node->aggregateId,
                $dryRun
                    ? RetranslationResult::skipped('dry-run')
                    : new RetranslationResult(
                        stalePropertyCommandsDispatched: $isVariant ? 0 : 1,
                        variantCommandsDispatched: $isVariant ? 1 : 0,
                    ),
            );
            if (!$dryRun) {
                $this->dispatchAsAi($cr, $command);
            }
        }

        return new WorkspaceSynchronizationResult($perNodeResults);
    }

    /**
     * Decide the single command for a visited node, or null when it should be skipped (not translatable, a tethered
     * child with no target variant yet, already in sync under `$skipExisting`, or no translatable values).
     *
     * @param array<string, StaleTranslation> $staleByNodeId
     */
    private function decideCommandForNode(
        NodeTypeManager $nodeTypeManager,
        Node $node,
        ContentSubgraphInterface $targetSubgraph,
        OriginDimensionSpacePoint $targetOrigin,
        array $staleByNodeId,
        string $sourceDeepl,
        string $targetDeepl,
        bool $skipExisting,
        bool $useCache,
    ): ?CommandInterface {
        $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        if (!$directive->enabled) {
            return null;
        }

        if ($targetSubgraph->findNodeById($node->aggregateId) === null) {
            // No target variant yet. A tethered child cannot be created independently — it materialises with its
            // non-tethered ancestor's CreateNodeVariant cascade (which the TranslationCommandHook then translates),
            // so we skip emitting one here.
            if ($node->classification->isTethered()) {
                return null;
            }
            // The cascade also translates the full property set of the new variant.
            return CreateNodeVariant::create(
                $node->workspaceName,
                $node->aggregateId,
                $node->originDimensionSpacePoint,
                $targetOrigin,
            );
        }

        // Target variant exists (true for tethered children once their ancestor was translated) — a SetNodeProperties
        // can refresh it directly, so tethered nodes are NOT excluded from this branch. Keep it untouched only when
        // keeping existing AND it is not stale — a stale record always forces a refresh.
        if ($skipExisting && !isset($staleByNodeId[$node->aggregateId->value])) {
            return null;
        }

        // Re-translate every translatable property (not just the stale slice).
        $allTranslatableNames = PropertyNames::fromArray(array_map(
            static fn ($t): PropertyName => $t->propertyName,
            iterator_to_array($directive->translatablePropertyNames),
        ));
        return $this->stalePropertyCommandBuilder->buildSetNodeProperties(
            nodeTypeManager: $nodeTypeManager,
            sourceNode: $node,
            stalePropertyNames: $allTranslatableNames,
            targetOrigin: $targetOrigin,
            sourceDeeplLanguage: $sourceDeepl,
            targetDeeplLanguage: $targetDeepl,
            useCache: $useCache,
        );
    }

    /**
     * Depth-first pre-order traversal of every root aggregate's source-dimension subtree. Children are followed
     * unfiltered (Document/ContentCollection/Content alike), mirroring the legacy `translateCommand` walk.
     *
     * @return \Generator<Node>
     */
    private function traverseSourceSubtrees(
        \Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface $contentGraph,
        ContentSubgraphInterface $sourceSubgraph,
    ): \Generator {
        foreach ($contentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            $rootNode = $sourceSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            yield from $this->traverseSubtree($sourceSubgraph, $rootNode);
        }
    }

    /**
     * @return \Generator<Node>
     */
    private function traverseSubtree(ContentSubgraphInterface $sourceSubgraph, Node $node): \Generator
    {
        yield $node;
        foreach ($sourceSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
            yield from $this->traverseSubtree($sourceSubgraph, $childNode);
        }
    }

    /**
     * Pre-fetch the stale records for the (targetWorkspace, targetDSP) slice, keyed by node aggregate id. Used only for
     * the `skipExisting` decision. Orphan rows (whose aggregate no longer exists in the ContentGraph) are filtered out
     * so they cannot contaminate the lookup — see `lostintranslation:reconcile` for the cleanup path.
     *
     * @return array<string, StaleTranslation>
     */
    private function collectStaleByNodeId(
        ContentRepository $cr,
        \Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface $contentGraph,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $targetOrigin,
    ): array {
        $staleByNodeId = [];
        foreach ($cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder->findAll() as $stale) {
            assert($stale instanceof StaleTranslation);
            if (!$stale->workspaceName->equals($targetWorkspaceName)) {
                continue;
            }
            if ($stale->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            if ($contentGraph->findNodeAggregateById($stale->nodeAggregateId) === null) {
                continue;
            }
            $staleByNodeId[$stale->nodeAggregateId->value] = $stale;
        }
        return $staleByNodeId;
    }

    private function dispatchAsAi(ContentRepository $cr, CommandInterface $command): void
    {
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        try {
            $cr->handle($command);
        } finally {
            $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        }
    }
}
