<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * A {@see SynchronizationRule} paired with the number of stale-translation records that a
 * {@see WorkspaceSynchronizer::synchronizeWorkspace()} run for that rule would currently process, plus whether the
 * rule's target workspace exists at all.
 *
 * `pendingCount === 0` means the target dimension is in sync with its source as far as the stale-translation projection
 * knows. `targetWorkspaceExists === false` means the rule cannot run yet: its target workspace must be created first
 * (synchronization never auto-creates it). Produced by {@see SynchronizationStatusProvider} and rendered in the backend
 * module overview.
 */
#[Flow\Proxy(false)]
final readonly class RuleSynchronizationStatus
{
    public function __construct(
        public SynchronizationRule $rule,
        public int $pendingCount,
        public bool $targetWorkspaceExists,
    ) {
    }
}
