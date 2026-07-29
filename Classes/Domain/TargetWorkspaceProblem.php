<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Why a synchronization rule cannot run at all — the outcome of the shared preflight in
 * {@see CrossWorkspaceSynchronizationTarget::prepare()}. `null` there means "may proceed"; one of these cases means the
 * rule is blocked until someone changes the workspace setup.
 *
 * A typed case rather than the bare sentence it renders to, because the same answer is consumed three ways and each
 * needs a different form of it:
 *  - the CLI and the backend flash messages want the sentence ({@see self::message()}),
 *  - the publish-driven {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook}
 *    wants it for a log warning,
 *  - the backend module's status column wants a translation key ({@see self::value}), so the reason is not shown in
 *    English to a German editor.
 * Deriving all three from one enum is what keeps the module from re-implementing the check and reporting a green
 * "up to date" for a rule that every publish silently skips.
 */
enum TargetWorkspaceProblem: string
{
    case Missing = 'missing';
    case NotBasedOnSource = 'notBasedOnSource';

    public function message(WorkspaceName $sourceWorkspaceName, WorkspaceName $targetWorkspaceName): string
    {
        return match ($this) {
            self::Missing => sprintf(
                'target workspace "%s" does not exist',
                $targetWorkspaceName->value,
            ),
            self::NotBasedOnSource => sprintf(
                'target workspace "%s" must be based on source workspace "%s" for cross-workspace synchronization',
                $targetWorkspaceName->value,
                $sourceWorkspaceName->value,
            ),
        };
    }
}
