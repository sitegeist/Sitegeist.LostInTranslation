<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * Outcome of a {@see WorkspaceSynchroniser::synchroniseWorkspace()} call. Carries one
 * {@see PerNodeSynchronisationResult} per stale-translation record that was iterated, so the CLI
 * can render per-node lines and aggregate totals. `skippedReason` is set when the orchestrator
 * itself short-circuited before iterating (e.g. validation failure); a non-null reason implies an
 * empty `perNodeResults`.
 */
#[Flow\Proxy(false)]
final readonly class WorkspaceSynchronisationResult
{
    /**
     * @param list<PerNodeSynchronisationResult> $perNodeResults
     */
    public function __construct(
        public array $perNodeResults,
        public ?string $skippedReason = null,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self([], $reason);
    }

    public function totalStalePropertyCommandsDispatched(): int
    {
        return array_sum(array_map(
            static fn (PerNodeSynchronisationResult $r): int => $r->result->stalePropertyCommandsDispatched,
            $this->perNodeResults,
        ));
    }

    public function totalVariantCommandsDispatched(): int
    {
        return array_sum(array_map(
            static fn (PerNodeSynchronisationResult $r): int => $r->result->variantCommandsDispatched,
            $this->perNodeResults,
        ));
    }

    public function totalSkippedNodes(): int
    {
        return count(array_filter(
            $this->perNodeResults,
            static fn (PerNodeSynchronisationResult $r): bool => $r->result->skippedReason !== null,
        ));
    }
}
