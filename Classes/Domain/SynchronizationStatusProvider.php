<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
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
        $statuses = [];
        foreach ($rules as $rule) {
            $statuses[] = new RuleSynchronizationStatus($rule, $this->pendingCountForRule($contentRepositoryId, $rule));
        }
        return $statuses;
    }

    public function pendingCountForRule(ContentRepositoryId $contentRepositoryId, SynchronizationRule $rule): int
    {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $targetWorkspaceName = WorkspaceName::fromString($rule->targetWorkspaceName);
        // A target workspace that does not exist cannot be out of sync (synchronizing into it is skipped — see
        // WorkspaceSynchronizer); report zero rather than faulting on a missing workspace.
        if ($cr->findWorkspaceByName($targetWorkspaceName) === null) {
            return 0;
        }
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint(
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->targetDimension])
        );
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        $targetContentGraph = $cr->getContentGraph($targetWorkspaceName);

        $count = 0;
        foreach ($finder->findAll() as $entry) {
            if (!$entry->workspaceName->equals($targetWorkspaceName)) {
                continue;
            }
            if ($entry->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            // Skip orphaned stale rows whose aggregate no longer exists (the projection does not cascade descendant
            // cleanup on removal — see `lostintranslation:reconcile`); WorkspaceSynchronizer skips them too.
            if ($targetContentGraph->findNodeAggregateById($entry->nodeAggregateId) === null) {
                continue;
            }
            $count++;
        }
        return $count;
    }
}
