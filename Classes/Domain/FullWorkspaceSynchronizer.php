<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
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
 *   - already translated by *this run's* cascade → skip; see {@see self::wasTranslatedByCascadeOf}.
 *   - target variant present + `$skipExisting` + no stale row → skip.
 *   - target variant present otherwise (i.e. the default `!$skipExisting`, OR a stale record exists) → translated
 *     `SetNodeProperties` for *every* translatable property. This applies to tethered children too: once their variant
 *     exists, a property change on the source is refreshed directly. The stale-record case is the load-bearing
 *     override: a stale node is always refreshed even under `$skipExisting`.
 *
 * `$skipExisting` defaults to **false**, i.e. `--full` re-translates every target variant that already existed when the
 * run started (a variant the run creates itself is translated once, by the cascade). It is the deliberate
 * exception to "the target dimension is a projection of the source": switching it on preserves target-side property edits
 * on nodes the source has not touched, at the cost of no longer converging them. It exists because re-asserting a
 * property costs a DeepL call per node, unlike re-asserting a tag — so on a large workspace the cheap-but-divergent run
 * is sometimes what you want. Opt in with the CLI's `--skip-existing`.
 *
 * Sole entry point {@see self::synchronizeWorkspaceFull} (CLI `synchronize --full`) dispatches inline, delegating
 * per-node decisions to {@see self::decideActionForNode} and traversal to {@see self::traverseSourceSubtrees}.
 * Dispatching inline is what makes this the only driver that can meet its own writes mid-walk — the reason
 * {@see self::wasTranslatedByCascadeOf} exists.
 *
 * Source and target workspace may differ: the source subtree is read from `sourceWorkspaceName` while every emitted
 * command (variant creation, property update) is dispatched into `targetWorkspaceName`. This supports workflows like a
 * full re-translation from published `live` into a `de-review` workspace without an intermediate publish.
 *
 * When source and target differ, the target workspace is first force-rebased onto its base (which must be the source
 * workspace). That brings the target's source dimension current with the source workspace — so the `CreateNodeVariant`
 * cascade (which reads the target's own source dimension) translates the latest source content — and materialises any
 * source nodes the target had not yet seen, so a node present only in the source no longer aborts the run. Conflicting
 * target-side changes are dropped (force); non-conflicting target-dimension review edits survive the rebase replay.
 */
class FullWorkspaceSynchronizer
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected AiCommandDispatcher $aiCommandDispatcher;

    #[Flow\Inject]
    protected StalePropertyCommandBuilder $stalePropertyCommandBuilder;

    #[Flow\Inject]
    protected DimensionValueDirectiveFactory $dimensionValueDirectiveFactory;

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
        bool $skipExisting = false,
        bool $dryRun = false,
    ): WorkspaceSynchronizationResult {
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

        $languagePair = $this->dimensionValueDirectiveFactory->tryResolveLanguagePair(
            $languageDimension,
            $sourceDimensionSpacePoint,
            $targetDimensionSpacePoint,
        );
        if ($languagePair === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }
        $sourceDeepl = $languagePair->sourceLanguage;
        $targetDeepl = $languagePair->targetLanguage;

        // Validate the target workspace (never auto-created) and, cross-workspace, force-rebase it onto the source — a
        // dry run only reports, so it must not rebase. Fail gracefully with a skip reason the CLI / Neos UI shows.
        $targetProblem = CrossWorkspaceSynchronizationTarget::prepare($cr, $sourceWorkspaceName, $targetWorkspaceName, !$dryRun);
        if ($targetProblem !== null) {
            return WorkspaceSynchronizationResult::skipped($targetProblem->message($sourceWorkspaceName, $targetWorkspaceName));
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        // Source structure + content are read from the source workspace; the target workspace only supplies the
        // "does the variant already exist" answer. They are the same graph in the common single-workspace case.
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);
        $targetContentGraph = $cr->getContentGraph($targetWorkspaceName);
        // The skip-existing lookup keys off the TARGET workspace — the slice this run clears, and the one every other
        // reader uses. {@see StaleTranslationFinder::findByWorkspaceAndOrigin} explains why that is indistinguishable
        // from the source's slice once the cross-workspace rebase above has run.
        $staleByNodeId = $this->collectStaleByNodeId($cr, $sourceContentGraph, $targetWorkspaceName, $targetOrigin);
        $staleTranslationMaintenance = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationMaintenance;
        $nodeTypeManager = $cr->getNodeTypeManager();

        $sourceSubgraph = $sourceContentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $targetContentGraph->getSubgraph($targetDimensionSpacePoint, VisibilityConstraints::createEmpty());

        $perNodeResults = [];
        /** @var array<string, true> $translatedByOwnCascade */
        $translatedByOwnCascade = [];
        foreach ($this->traverseSourceSubtrees($sourceContentGraph, $sourceSubgraph) as [$parentNode, $node]) {
            // Nothing to do for a node this run's own CreateNodeVariant cascade has already translated — see
            // wasTranslatedByCascadeOf(). Skipped without pruning, exactly like the variant branch below: the cascade's
            // own NodePropertiesWereSet clears the stale row, so the row is not unsatisfiable and must not be pruned.
            if ($parentNode !== null && $this->wasTranslatedByCascadeOf($nodeTypeManager, $parentNode, $node, $translatedByOwnCascade)) {
                $translatedByOwnCascade[$node->aggregateId->value] = true;
                continue;
            }
            $action = $this->decideActionForNode(
                $nodeTypeManager,
                $node,
                $targetWorkspaceName,
                $targetSubgraph,
                $targetOrigin,
                $staleByNodeId,
                $skipExisting,
            );
            if ($action instanceof CreateNodeVariant) {
                // Marked on a dry run too, although nothing is dispatched: it is what makes the preview walk the same
                // branches as the real run, so the two agree on the tethered descendants they report.
                $translatedByOwnCascade[$node->aggregateId->value] = true;
                $perNodeResults[] = new PerNodeSynchronizationResult(
                    $node->aggregateId,
                    new RetranslationResult(stalePropertyCommandsDispatched: 0, variantCommandsDispatched: 1),
                );
                if (!$dryRun) {
                    $this->aiCommandDispatcher->dispatch($cr, $action);
                }
                continue;
            }
            if ($action !== null) {
                // Property update. Building the `SetNodeProperties` IS the DeepL round-trip, so a preview asks whether
                // the source values would produce a command rather than producing one and discarding it — otherwise
                // `--dry-run` would spend the very translation budget it exists to estimate. Both answers come from the
                // same collect step, so the preview counts exactly the nodes the real run writes.
                $command = $dryRun ? null : $this->stalePropertyCommandBuilder->buildSetNodeProperties(
                    nodeTypeManager: $nodeTypeManager,
                    sourceNode: $node,
                    stalePropertyNames: $action,
                    targetOrigin: $targetOrigin,
                    sourceDeeplLanguage: $sourceDeepl,
                    targetDeeplLanguage: $targetDeepl,
                    targetWorkspaceName: $targetWorkspaceName,
                );
                $writesProperties = $dryRun
                    ? $this->stalePropertyCommandBuilder->wouldBuildSetNodeProperties($nodeTypeManager, $node, $action)
                    : $command !== null;
                if ($writesProperties) {
                    $perNodeResults[] = new PerNodeSynchronizationResult(
                        $node->aggregateId,
                        new RetranslationResult(stalePropertyCommandsDispatched: 1, variantCommandsDispatched: 0),
                    );
                    if ($command !== null) {
                        $this->aiCommandDispatcher->dispatch($cr, $command);
                    }
                    continue;
                }
            }
            // Nothing to do for this node — prune the stale row if no future event will ever clear it (a dry run only
            // reports, so it must not write). See StaleRecordReconciler for the two no-op cases.
            $stale = $staleByNodeId[$node->aggregateId->value] ?? null;
            if (!$dryRun && $stale !== null) {
                StaleRecordReconciler::pruneIfUnsatisfiable(
                    $staleTranslationMaintenance,
                    $targetWorkspaceName,
                    $stale,
                    $node,
                    $sourceSubgraph,
                    $targetSubgraph,
                );
            }
        }

        // Tag reconcile: converge each target node's explicit subtree tags onto the source by diffing the two dimensions
        // — hide/show, the `removed` soft-removal tag (i.e. deletions and restores) and any other tag. Unconditional,
        // like everywhere else: the target dimension is a projection of the source. Content graphs are re-read so
        // variants created earlier in this run are included. See TargetTagReconciler.
        $tagCommands = TargetTagReconciler::collect(
            $cr->getContentGraph($targetWorkspaceName),
            $cr->getContentGraph($sourceWorkspaceName),
            $sourceDimensionSpacePoint,
            $targetDimensionSpacePoint,
            $targetWorkspaceName,
        );
        $perNodeResults = array_merge($perNodeResults, MirroredCommandDispatcher::dispatch(
            $cr,
            $this->aiCommandDispatcher,
            $tagCommands,
            $dryRun,
        ));

        return new WorkspaceSynchronizationResult($perNodeResults);
    }

    /**
     * Decide what a visited node needs: a ready-to-dispatch `CreateNodeVariant`, the property names to translate into
     * an existing target variant, or null when it should be skipped (not translatable, a tethered child with no target
     * variant yet, or already in sync under `$skipExisting`).
     *
     * Returning the property *names* rather than the built `SetNodeProperties` keeps the decision free of DeepL, so a
     * dry run can reach it too — see the caller.
     *
     * @param array<string, StaleTranslation> $staleByNodeId
     */
    private function decideActionForNode(
        NodeTypeManager $nodeTypeManager,
        Node $node,
        WorkspaceName $targetWorkspaceName,
        ContentSubgraphInterface $targetSubgraph,
        OriginDimensionSpacePoint $targetOrigin,
        array $staleByNodeId,
        bool $skipExisting,
    ): CreateNodeVariant|PropertyNames|null {
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
            // The cascade also translates the full property set of the new variant. Dispatch into the target
            // workspace, varying from the source-dimension node that the target workspace shares with the source.
            return CreateNodeVariant::create(
                $targetWorkspaceName,
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
        return PropertyNames::fromArray(array_map(
            static fn ($t): PropertyName => $t->propertyName,
            iterator_to_array($directive->translatablePropertyNames),
        ));
    }

    /**
     * Whether the `CreateNodeVariant` this run dispatched for an ancestor has *already translated* `$node`, making the
     * walk's own `SetNodeProperties` for it a second DeepL call for a result that cannot differ.
     *
     * The redundancy exists because this synchronizer dispatches inline, mid-walk (the other drivers collect first and
     * dispatch afterwards): creating a variant materialises the node's tethered descendants in the target and
     * {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} translates each of them
     * from the same source values — so by the time the walk reaches such a child it is an existing variant, which
     * `--full` refreshes by default. One wasted call per tethered node per run, forever.
     *
     * `$translatedByOwnCascade` holds the closure built up along the walk: the nodes we emitted a `CreateNodeVariant`
     * for, plus everything reached from them through this predicate. Since the traversal is depth-first pre-order, the
     * parent is always decided before its children, so testing the immediate parent is enough.
     *
     * The tethered child is matched the way the hook's cascade matches it — by the *parent node type's* tethered
     * definitions, not by classification alone. That is deliberate: a node tethered in the graph but no longer declared
     * by its parent's node type is created by the CR's variant cascade yet never reaches the hook's translation
     * cascade, so it must keep being refreshed by the walk.
     *
     * @param array<string, true> $translatedByOwnCascade
     */
    private function wasTranslatedByCascadeOf(
        NodeTypeManager $nodeTypeManager,
        Node $parentNode,
        Node $node,
        array $translatedByOwnCascade,
    ): bool {
        if (!isset($translatedByOwnCascade[$parentNode->aggregateId->value])) {
            return false;
        }
        if (!$node->classification->isTethered() || $node->name === null) {
            return false;
        }
        return $nodeTypeManager->getNodeType($parentNode->nodeTypeName)
            ?->tetheredNodeTypeDefinitions->contain($node->name) === true;
    }

    /**
     * Depth-first pre-order traversal of every root aggregate's source-dimension subtree. Children are followed
     * unfiltered (Document/ContentCollection/Content alike), mirroring the legacy `translateCommand` walk.
     *
     * Yields each node together with its parent (null for a root), which {@see self::wasTranslatedByCascadeOf} needs to
     * decide whether the node rode along with an ancestor's variant cascade.
     *
     * @return \Generator<array{?Node, Node}>
     */
    private function traverseSourceSubtrees(
        ContentGraphInterface $contentGraph,
        ContentSubgraphInterface $sourceSubgraph,
    ): \Generator {
        foreach ($contentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            $rootNode = $sourceSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            yield from $this->traverseSubtree($sourceSubgraph, null, $rootNode);
        }
    }

    /**
     * @return \Generator<array{?Node, Node}>
     */
    private function traverseSubtree(ContentSubgraphInterface $sourceSubgraph, ?Node $parentNode, Node $node): \Generator
    {
        yield [$parentNode, $node];
        foreach ($sourceSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
            yield from $this->traverseSubtree($sourceSubgraph, $node, $childNode);
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
        ContentGraphInterface $contentGraph,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $targetOrigin,
    ): array {
        $staleByNodeId = [];
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        foreach ($finder->findByWorkspaceAndOrigin($targetWorkspaceName, $targetOrigin) as $stale) {
            assert($stale instanceof StaleTranslation);
            if ($contentGraph->findNodeAggregateById($stale->nodeAggregateId) === null) {
                continue;
            }
            $staleByNodeId[$stale->nodeAggregateId->value] = $stale;
        }
        return $staleByNodeId;
    }
}
