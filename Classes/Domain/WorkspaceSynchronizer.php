<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;

/**
 * Workspace-level orchestrator on top of {@see Retranslator}.
 *
 * Loads every stale-translation record matching the target (workspace, originDimensionSpacePoint) and dispatches one
 * {@see Retranslator::retranslateSubtree()} call per record. The dispatch chain is idempotent: a parent's subtree walk
 * that clears child stale records causes later iterations to come back as `RetranslationResult::isNoOp()` rather than
 * re-translating.
 *
 * Source and target workspace may differ. The projection records stale rows in the workspace where the source content
 * was edited, so the stale records driving the run are read from `sourceWorkspaceName`; every retranslation command is
 * dispatched into `targetWorkspaceName`. This supports workflows like "retranslate from published `live` into
 * `de-review`" without an intermediate publish.
 *
 * When source and target differ, the target workspace is first force-rebased onto its base (which must be the source
 * workspace). This brings the target's source dimension current with the source workspace — so the translation always
 * reads the latest source content (including via the `CreateNodeVariant` cascade, which reads the target's own source
 * dimension) — and materialises any source nodes the target had not yet seen. Conflicting target-side changes are
 * dropped (force); non-conflicting target-dimension review edits survive the rebase replay, which is the intended
 * source↔target divergence. A `$dryRun` skips the rebase, since it mutates the target irreversibly.
 *
 * `sourceDimension` must equal the configured `referenceLanguage` of `targetDimension`; a mismatch short-circuits with
 * {@see WorkspaceSynchronizationResult::skipped()} so the caller (typically the `synchronize` CLI) can surface a clear
 * error.
 */
class WorkspaceSynchronizer
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected Retranslator $retranslator;

    #[Flow\Inject]
    protected AiCommandDispatcher $aiCommandDispatcher;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    /**
     * Convenience wrapper that runs {@see self::synchronizeWorkspace()} for a configured {@see SynchronizationRule},
     * deriving the source/target {@see DimensionSpacePoint}s from the rule's dimension values and the configured
     * language dimension. Shared by the publish-driven UI prompt and the backend module "sync now".
     */
    public function synchronizeRule(
        ContentRepositoryId $contentRepositoryId,
        SynchronizationRule $rule,
        bool $dryRun = false,
    ): WorkspaceSynchronizationResult {
        return $this->synchronizeWorkspace(
            contentRepositoryId: $contentRepositoryId,
            sourceWorkspaceName: WorkspaceName::fromString($rule->sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->sourceDimension]),
            targetWorkspaceName: WorkspaceName::fromString($rule->targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->targetDimension]),
            dryRun: $dryRun,
            scope: $rule->scope,
        );
    }

    /**
     * @param SynchronizationScope $scope whether missing Document variants may be created. Under
     *        {@see SynchronizationScope::Content} a stale record whose closest Document is absent from the target
     *        dimension is skipped, exactly as the publish-driven {@see
     *        \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook} skips it — otherwise
     *        clicking "sync now" would create the very Documents the scope exists to keep out. Defaults to
     *        {@see SynchronizationScope::Document} for the rule-less CLI, which mirrors the whole structure.
     */
    public function synchronizeWorkspace(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $dryRun = false,
        SynchronizationScope $scope = SynchronizationScope::Document,
    ): WorkspaceSynchronizationResult {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
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

        // Validate the target workspace (never auto-created) and, cross-workspace, force-rebase it onto the source so
        // the translation reads the latest source content. Fail gracefully with a skip reason the CLI / Neos UI shows.
        // A dry run must not rebase: the rebase is a destructive, non-reversible mutation of the target workspace
        // (new content stream, conflicting target edits dropped), and `--dry-run` promises to report only. The
        // trade-off is that a cross-workspace dry run reports against the UN-rebased target, so its counts can differ
        // from the real run — source nodes the target has not seen yet are missing, and their records are skipped.
        $skipReason = CrossWorkspaceSynchronizationTarget::prepare($cr, $sourceWorkspaceName, $targetWorkspaceName, !$dryRun);
        if ($skipReason !== null) {
            return WorkspaceSynchronizationResult::skipped($skipReason);
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        // Stale rows are flagged against the workspace the source content was edited in, so they drive the run from
        // the source side; existence is checked there too. In the single-workspace case source == target.
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);
        // Source side EXCLUDES soft-removed nodes: Neos 9.1 deletes by tagging `removed`, so a soft-removed source node
        // is a DELETED node and must never be a translation source. `--full` reads its source the same way; the
        // publish-driven hook likewise. (The deletion reaches the target through the tag reconcile below.)
        $sourceSubgraph = $sourceContentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        // Target subgraph for ancestor-coverage checks. `withoutRestrictions` so a present-but-disabled
        // ancestor still counts as covering the target dimension — disabled state does not affect whether
        // a descendant variant can be created (CR coverage is independent of visibility).
        $targetContentGraph = $cr->getContentGraph($targetWorkspaceName);
        $targetSubgraph = $targetContentGraph->getSubgraph($targetDimensionSpacePoint, VisibilityConstraints::createEmpty());

        // Stale records arrive in primary-key order, not hierarchical order. Creating a node variant requires its
        // parent to already cover the target dimension (CR `requireNodeAggregateToCoverDimensionSpacePoint`), so
        // synchronizing a child document before its parent would abort with "Node aggregate <parent> does currently
        // not cover dimension space point". We therefore pair each matching record with its source-tree depth and
        // process them ancestor-before-descendant — a parent document's `retranslateSubtree` (which creates its variant)
        // runs before any descendant's. This mirrors the publish-driven {@see SynchronizationCommandHook}. PHP's sort
        // is stable (>= 8.0), so records at equal depth keep their original (id) order.
        /** @var list<array{depth:int,entry:StaleTranslation}> $plannedEntries */
        $plannedEntries = [];
        // Stale rows are flagged against the source workspace, so the slice we drive the run from keys off it.
        foreach ($finder->findByWorkspaceAndOrigin($sourceWorkspaceName, $targetOrigin) as $entry) {
            // Skip orphaned stale rows whose aggregate no longer exists in the ContentGraph (the projection
            // does not cascade descendant cleanup on node removal — see `lostintranslation:reconcile`).
            if ($sourceContentGraph->findNodeAggregateById($entry->nodeAggregateId) === null) {
                continue;
            }
            $plannedEntries[] = [
                'depth' => NodeTreeDepth::of($sourceSubgraph, $entry->nodeAggregateId),
                'entry' => $entry,
            ];
        }
        usort($plannedEntries, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);

        // Ids that this run will (attempt to) translate, i.e. those backed by a stale record. The
        // ancestor check below uses this set to know which missing ancestors will be created earlier
        // in the run (depth-sort) versus which are holes the stale-driven run cannot fill.
        $plannedStaleIds = [];
        foreach ($plannedEntries as $plannedEntry) {
            $plannedStaleIds[$plannedEntry['entry']->nodeAggregateId->value] = true;
        }

        $perNodeResults = [];
        foreach ($plannedEntries as $plannedEntry) {
            $entry = $plannedEntry['entry'];
            // A node whose ancestor document is missing in the target and has no stale record of its
            // own cannot be created by this stale-driven run: the missing document never gets a
            // CreateNodeVariant, so its tethered content collection never materialises and the node
            // below cannot be varied (CR `requireNodeAggregateToCoverDimensionSpacePoint`). Rather than
            // letting the CR abort the whole run, skip the node and let the caller hint at `--full`.
            $missingAncestorReason = $this->reasonAncestorCannotBeCreated(
                $sourceSubgraph,
                $targetSubgraph,
                $entry->nodeAggregateId,
                $plannedStaleIds,
            );
            if ($missingAncestorReason !== null) {
                $perNodeResults[] = new PerNodeSynchronizationResult(
                    $entry->nodeAggregateId,
                    RetranslationResult::skippedRequiringFullSync($missingAncestorReason),
                );
                continue;
            }
            // Content scope never creates Document variants, so skip a record whose closest self-or-ancestor Document is
            // absent from the target dimension. `findClosestNode` walks `self -> ancestors`, so for a Document node it
            // returns the node itself: a Document missing in the target is skipped (no auto-create), while one already
            // present passes and is re-translated like any other node. Identical to the publish-driven hook's gate —
            // without this, "sync now" would create exactly the Documents the scope exists to keep out.
            if ($scope === SynchronizationScope::Content) {
                $documentNode = $sourceSubgraph->findClosestNode(
                    $entry->nodeAggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document'),
                );
                if ($documentNode === null || $targetSubgraph->findNodeById($documentNode->aggregateId) === null) {
                    $perNodeResults[] = new PerNodeSynchronizationResult(
                        $entry->nodeAggregateId,
                        RetranslationResult::skipped('Content scope: containing document is not present in the target dimension'),
                    );
                    continue;
                }
            }
            if ($dryRun) {
                $perNodeResults[] = new PerNodeSynchronizationResult(
                    $entry->nodeAggregateId,
                    RetranslationResult::skipped('dry-run'),
                );
                continue;
            }
            $result = $this->retranslator->retranslateSubtree(
                contentRepositoryId: $contentRepositoryId,
                workspaceName: $targetWorkspaceName,
                nodeAggregateId: $entry->nodeAggregateId,
                targetDimensionSpacePoint: $targetDimensionSpacePoint,
                sourceWorkspaceName: $sourceWorkspaceName,
            );
            $perNodeResults[] = new PerNodeSynchronizationResult($entry->nodeAggregateId, $result);
        }

        // Tag reconcile: converge each target-dimension node's explicit subtree tags onto the source — hide/show, the
        // `removed` soft-removal tag (i.e. deletions and restores) and any other tag. Unconditional: the target
        // dimension is a projection of the source, so its tag state is the source's. Decoupled from any publish, so we
        // diff the two dimensions rather than reading tag events (which the publish-driven hook uses). Content graphs
        // are re-read here so variants created earlier in this run are included. See TargetTagReconciler.
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
     * Walk `$nodeAggregateId`'s source-tree ancestors (nearest first) to determine whether the
     * stale-driven run can create its target variant. Returns a human-readable reason when it cannot —
     * a non-tethered (document) ancestor is missing in the target dimension and has no stale record, so
     * nothing in this run will create it — or `null` when the ancestor chain is satisfiable.
     *
     * @param array<string,true> $plannedStaleIds ids backed by a stale record this run will process
     */
    private function reasonAncestorCannotBeCreated(
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
        NodeAggregateId $nodeAggregateId,
        array $plannedStaleIds,
    ): ?string {
        foreach ($sourceSubgraph->findAncestorNodes($nodeAggregateId, FindAncestorNodesFilter::create()) as $ancestor) {
            if ($targetSubgraph->findNodeById($ancestor->aggregateId) !== null) {
                // This ancestor already covers the target; by the CR invariant (a node covers a DSP
                // only if its parent does) every higher ancestor covers it too — the chain is fine.
                return null;
            }
            if ($ancestor->classification->isTethered()) {
                // A tethered node materialises via its document ancestor's CreateNodeVariant cascade;
                // its fate is decided by that (non-tethered) ancestor, checked further up the loop.
                continue;
            }
            if (!isset($plannedStaleIds[$ancestor->aggregateId->value])) {
                return sprintf(
                    'ancestor %s is missing in the target dimension and has no pending translation; '
                    . 'run synchronize --full to create it',
                    $ancestor->aggregateId->value,
                );
            }
            // The ancestor has a stale record, so depth-sort creates it before this node — but keep
            // walking up to confirm ITS own ancestors are satisfiable too.
        }
        return null;
    }
}
