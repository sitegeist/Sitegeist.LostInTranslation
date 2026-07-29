<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * A {@see SynchronizationRule} paired with the number of stale-translation records that a
 * {@see WorkspaceSynchronizer::synchronizeWorkspace()} run for that rule would currently process, plus the reason the
 * rule cannot run at all, if any.
 *
 * `pendingCount === 0` means the target dimension is in sync with its source as far as the stale-translation projection
 * knows. A non-null `$targetProblem` means the rule is blocked until the workspace setup changes — and then
 * `pendingCount` is reported as 0, because work a rule can never perform is not pending work: counting it would keep
 * the module permanently "out of sync" and keep re-raising the post-publish prompt for a sync that skips immediately.
 *
 * Produced by {@see SynchronizationStatusProvider} and rendered in the backend module overview.
 */
#[Flow\Proxy(false)]
final readonly class RuleSynchronizationStatus
{
    public function __construct(
        public SynchronizationRule $rule,
        public int $pendingCount,
        public ?TargetWorkspaceProblem $targetProblem,
    ) {
    }
}
