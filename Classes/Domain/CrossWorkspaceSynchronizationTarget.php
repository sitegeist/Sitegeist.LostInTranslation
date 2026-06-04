<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Shared cross-workspace synchronization preflight.
 *
 * The target workspace is NEVER auto-created — materialising a workspace is a deliberate editor/admin action, not a
 * side effect of synchronization. For a cross-workspace rule the target must be based on the source workspace so it can
 * be force-rebased onto it: this brings the target's source dimension current with the source (every source node
 * exists in the target, and the translation reads the latest source) and — via the projection's
 * `replaceWorkspaceEntries` on `WorkspaceWasRebased` — refreshes the target's stale rows. The target's own review edits
 * are replayed on top; genuinely-conflicting target changes are dropped (the published source wins). A same-workspace
 * rule needs no rebase.
 */
final class CrossWorkspaceSynchronizationTarget
{
    /**
     * Returns null when synchronization may proceed (including the same-workspace case), or a human-readable skip
     * reason when it cannot — the target workspace does not exist, or (cross-workspace) is not based on the source.
     *
     * @param bool $rebase whether to actually perform the force-rebase (pass false for a dry run)
     */
    public static function prepare(
        ContentRepository $contentRepository,
        WorkspaceName $sourceWorkspaceName,
        WorkspaceName $targetWorkspaceName,
        bool $rebase = true,
    ): ?string {
        $targetWorkspace = $contentRepository->findWorkspaceByName($targetWorkspaceName);
        if ($targetWorkspace === null) {
            return sprintf('target workspace "%s" does not exist', $targetWorkspaceName->value);
        }
        if ($sourceWorkspaceName->equals($targetWorkspaceName)) {
            return null;
        }
        if ($targetWorkspace->baseWorkspaceName === null || !$targetWorkspace->baseWorkspaceName->equals($sourceWorkspaceName)) {
            return sprintf(
                'target workspace "%s" must be based on source workspace "%s" for cross-workspace synchronization',
                $targetWorkspaceName->value,
                $sourceWorkspaceName->value,
            );
        }
        if ($rebase) {
            $contentRepository->handle(
                RebaseWorkspace::create($targetWorkspaceName)
                    ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE)
            );
        }
        return null;
    }
}
