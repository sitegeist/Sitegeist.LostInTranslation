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
 * The count deliberately mirrors {@see WorkspaceSynchronizer::synchronizeWorkspace()}'s own stale-row filter
 * (stale rows flagged against the rule's `sourceWorkspaceName` at the target origin whose aggregate still exists) so the
 * number shown equals what a subsequent "sync now" will actually process. It does NOT rebase the target (which the real
 * run does in the cross-workspace case) because a status read must stay side-effect free; in that case the number is a
 * lower bound that excludes source nodes not yet materialised in the target.
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
        $sourceWorkspaceName = WorkspaceName::fromString($rule->sourceWorkspaceName);
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint(
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $rule->targetDimension])
        );
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);

        $count = 0;
        foreach ($finder->findAll() as $entry) {
            if (!$entry->workspaceName->equals($sourceWorkspaceName)) {
                continue;
            }
            if ($entry->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            // Skip orphaned stale rows whose aggregate no longer exists (the projection does not cascade descendant
            // cleanup on removal — see `lostintranslation:reconcile`); WorkspaceSynchronizer skips them too.
            if ($sourceContentGraph->findNodeAggregateById($entry->nodeAggregateId) === null) {
                continue;
            }
            $count++;
        }
        return $count;
    }
}
