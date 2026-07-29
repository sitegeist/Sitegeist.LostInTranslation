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
     * Returns null when synchronization may proceed (including the same-workspace case), or the
     * {@see TargetWorkspaceProblem} blocking it — the target workspace does not exist, or (cross-workspace) is not
     * based on the source.
     *
     * This is the ONLY place those two conditions are decided. Every reader — the CLI, the backend module's status
     * column, the Neos UI prompt and the publish-driven hook — asks here rather than re-deriving them, so a rule that
     * cannot run reads as blocked everywhere at once instead of in whichever surface happens to check.
     *
     * With `$rebase = false` the call is side-effect free, which is what makes it usable from the read-only status
     * paths and from `--dry-run`.
     *
     * @param bool $rebase whether to actually perform the force-rebase (pass false for a dry run or a read-only check)
     */
    public static function prepare(
        ContentRepository $contentRepository,
        WorkspaceName $sourceWorkspaceName,
        WorkspaceName $targetWorkspaceName,
        bool $rebase = true,
    ): ?TargetWorkspaceProblem {
        $targetWorkspace = $contentRepository->findWorkspaceByName($targetWorkspaceName);
        if ($targetWorkspace === null) {
            return TargetWorkspaceProblem::Missing;
        }
        if ($sourceWorkspaceName->equals($targetWorkspaceName)) {
            return null;
        }
        if ($targetWorkspace->baseWorkspaceName === null || !$targetWorkspace->baseWorkspaceName->equals($sourceWorkspaceName)) {
            return TargetWorkspaceProblem::NotBasedOnSource;
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
