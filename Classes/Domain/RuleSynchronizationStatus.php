<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * A {@see SynchronizationRule} paired with the number of stale-translation records that a
 * {@see WorkspaceSynchronizer::synchronizeWorkspace()} run for that rule would currently process.
 *
 * `pendingCount === 0` means the target dimension is in sync with its source as far as the stale-translation projection
 * knows. Produced by {@see SynchronizationStatusProvider} and rendered both in the Neos UI post-publish prompt and the
 * backend module overview.
 */
#[Flow\Proxy(false)]
final readonly class RuleSynchronizationStatus
{
    public function __construct(
        public SynchronizationRule $rule,
        public int $pendingCount,
    ) {
    }
}
