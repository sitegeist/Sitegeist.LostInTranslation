<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;

/**
 * Read-only "how much is out of sync" provider, shared by the {@see WorkspaceSynchronizationController} (Neos UI
 * post-publish prompt) and the {@see \Sitegeist\LostInTranslation\Controller\LostInTranslationModuleController} backend
 * overview so both report identical numbers.
 *
 * "Out of sync" is measured on the TARGET: the count is the number of stale-translation rows flagged against the rule's
 * `targetWorkspaceName` at the `targetDimension` origin whose aggregate still exists in the target. Once a sync has
 * (re-)translated the target those rows are cleared, so the number drops to zero — which is what the backend module and
 * the UI prompt need to reflect. (Counting the SOURCE workspace instead would never reach zero for a cross-workspace
 * rule, since translating into the target leaves the source's own stale rows untouched.)
 *
 * The read is side-effect free: it does NOT rebase the target (which the real cross-workspace run does), so for a target
 * that has not yet been rebased onto the source the number is a lower bound — it only sees source changes already
 * materialised in the target.
 *
 * Whether a rule can run at all is not re-derived here — it comes from the same preflight every driver uses
 * ({@see CrossWorkspaceSynchronizationTarget::prepare()}, with the rebase suppressed). That matters: this surface used
 * to check only "does the target workspace exist", so a rule whose target existed but was based on the wrong workspace
 * — one that every publish and every manual sync skips — was reported as a green "up to date".
 *
 * Rows whose source node has been DELETED (soft-removed, i.e. carrying the `removed` tag) are skipped. The projection
 * deliberately does not handle tag events, so deleting a node leaves its stale rows behind, and no driver will ever
 * satisfy them — a deleted node is not a translation source. Counting them would leave the backend module permanently
 * "out of sync" and re-raise the post-publish "sync now" prompt for work that can never complete. They are filtered here
 * at READ time rather than pruned from the projection, so that restoring the node from the trash bin brings its pending
 * translation back with it.
 */
#[Flow\Scope('singleton')]
class SynchronizationStatusProvider
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    /**
     * @return list<RuleSynchronizationStatus>
     */
    public function forRules(ContentRepositoryId $contentRepositoryId, SynchronizationRules $rules): array
    {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $statuses = [];
        foreach ($rules as $rule) {
            $targetProblem = $this->targetProblemForRule($cr, $rule);
            $statuses[] = new RuleSynchronizationStatus(
                $rule,
                $targetProblem === null ? $this->countPending($cr, $rule) : 0,
                $targetProblem,
            );
        }
        return $statuses;
    }

    public function pendingCountForRule(ContentRepositoryId $contentRepositoryId, SynchronizationRule $rule): int
    {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        // A rule that cannot run at all has no pending work to report: synchronizing it is skipped outright (see
        // WorkspaceSynchronizer), so counting its rows would leave the module permanently out of sync and keep
        // re-raising the Neos UI's post-publish prompt for a sync that does nothing.
        if ($this->targetProblemForRule($cr, $rule) !== null) {
            return 0;
        }
        return $this->countPending($cr, $rule);
    }

    /**
     * Ask the shared preflight — the same one every driver runs — whether this rule can run, WITHOUT rebasing: this is
     * a read-only status path and the force-rebase it would otherwise perform is a destructive mutation of the target
     * workspace.
     */
    private function targetProblemForRule(ContentRepository $cr, SynchronizationRule $rule): ?TargetWorkspaceProblem
    {
        return CrossWorkspaceSynchronizationTarget::prepare(
            $cr,
            WorkspaceName::fromString($rule->sourceWorkspaceName),
            WorkspaceName::fromString($rule->targetWorkspaceName),
            rebase: false,
        );
    }

    private function countPending(ContentRepository $cr, SynchronizationRule $rule): int
    {
        $targetWorkspaceName = WorkspaceName::fromString($rule->targetWorkspaceName);
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint(
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->targetDimension])
        );
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        $targetContentGraph = $cr->getContentGraph($targetWorkspaceName);
        // The rule's SOURCE dimension read with FULL visibility, so a soft-removed (deleted) source node is still
        // readable and can be recognised as deleted below.
        $sourceSubgraph = $targetContentGraph->getSubgraph(
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->sourceDimension]),
            VisibilityConstraints::createEmpty(),
        );
        $removedTag = NeosSubtreeTag::removed();

        $count = 0;
        foreach ($finder->findByWorkspaceAndOrigin($targetWorkspaceName, $targetOrigin) as $entry) {
            // Skip orphaned stale rows whose aggregate no longer exists (the projection does not cascade descendant
            // cleanup on removal — see `lostintranslation:reconcile`); WorkspaceSynchronizer skips them too.
            if ($targetContentGraph->findNodeAggregateById($entry->nodeAggregateId) === null) {
                continue;
            }
            // Skip rows whose source node was deleted: nothing will ever translate them, so counting them would keep
            // reporting work that cannot complete (see the class docblock). Checking the `removed` tag specifically —
            // rather than plain visibility — leaves every other case counted exactly as before, including a source node
            // the (un-rebased) target has not seen yet.
            $sourceNode = $sourceSubgraph->findNodeById($entry->nodeAggregateId);
            if ($sourceNode !== null && $sourceNode->tags->contain($removedTag)) {
                continue;
            }
            $count++;
        }
        return $count;
    }
}
