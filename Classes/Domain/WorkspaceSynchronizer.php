<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;

/**
 * Workspace-level orchestrator on top of {@see Retranslator}.
 *
 * Loads every stale-translation record matching the target (workspace, originDimensionSpacePoint) and dispatches one
 * {@see Retranslator::retranslateNode()} call per record. The dispatch chain is idempotent: a parent's subtree walk
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
 * source↔target divergence.
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
        );
    }

    public function synchronizeWorkspace(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $dryRun = false,
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

        // The target workspace is never auto-created: a config rule may point at a workspace that does not exist yet,
        // but materialising workspaces is a deliberate editor/admin action, not a side effect of synchronization. Fail
        // gracefully so the caller (CLI / Neos UI) can surface a clear error notification instead of faulting on a
        // missing workspace further down.
        $targetWorkspace = $cr->findWorkspaceByName($targetWorkspaceName);
        if ($targetWorkspace === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'target workspace "%s" does not exist',
                $targetWorkspaceName->value,
            ));
        }
        // Cross-workspace synchronization requires the target to be based on the source workspace, so the rebase below
        // can bring it current with the source. A target that is neither the source itself nor based on it cannot be
        // synchronized this way.
        if (!$sourceWorkspaceName->equals($targetWorkspaceName)) {
            if ($targetWorkspace->baseWorkspaceName === null || !$targetWorkspace->baseWorkspaceName->equals($sourceWorkspaceName)) {
                return WorkspaceSynchronizationResult::skipped(sprintf(
                    'target workspace "%s" must be based on source workspace "%s" for cross-workspace synchronization',
                    $targetWorkspaceName->value,
                    $sourceWorkspaceName->value,
                ));
            }
            // Bring the target current with its base (the source workspace) before reading it, so the source content
            // the translation reads from the target matches the source workspace and every source node exists in the
            // target. Conflicting target-side changes are dropped (force); non-conflicting target-dimension review
            // edits survive the rebase replay.
            $cr->handle(
                RebaseWorkspace::create($targetWorkspaceName)
                    ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE)
            );
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        // Stale rows are flagged against the workspace the source content was edited in, so they drive the run from
        // the source side; existence is checked there too. In the single-workspace case source == target.
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);
        $sourceSubgraph = $sourceContentGraph->getSubgraph($sourceDimensionSpacePoint, VisibilityConstraints::withoutRestrictions());

        // Stale records arrive in primary-key order, not hierarchical order. Creating a node variant requires its
        // parent to already cover the target dimension (CR `requireNodeAggregateToCoverDimensionSpacePoint`), so
        // synchronizing a child document before its parent would abort with "Node aggregate <parent> does currently
        // not cover dimension space point". We therefore pair each matching record with its source-tree depth and
        // process them ancestor-before-descendant — a parent document's `retranslateNode` (which creates its variant)
        // runs before any descendant's. This mirrors the publish-driven {@see SynchronizationCommandHook}. PHP's sort
        // is stable (>= 8.0), so records at equal depth keep their original (id) order.
        /** @var list<array{depth:int,entry:StaleTranslation}> $plannedEntries */
        $plannedEntries = [];
        foreach ($finder->findAll() as $entry) {
            if (!$entry->workspaceName->equals($sourceWorkspaceName)) {
                continue;
            }
            if ($entry->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
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

        $perNodeResults = [];
        foreach ($plannedEntries as $plannedEntry) {
            $entry = $plannedEntry['entry'];
            if ($dryRun) {
                $perNodeResults[] = new PerNodeSynchronizationResult(
                    $entry->nodeAggregateId,
                    RetranslationResult::skipped('dry-run'),
                );
                continue;
            }
            $result = $this->retranslator->retranslateNode(
                contentRepositoryId: $contentRepositoryId,
                workspaceName: $targetWorkspaceName,
                nodeAggregateId: $entry->nodeAggregateId,
                targetDimensionSpacePoint: $targetDimensionSpacePoint,
                sourceWorkspaceName: $sourceWorkspaceName,
            );
            $perNodeResults[] = new PerNodeSynchronizationResult($entry->nodeAggregateId, $result);
        }

        return new WorkspaceSynchronizationResult($perNodeResults);
    }
}
