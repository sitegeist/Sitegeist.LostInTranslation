<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * One rule of `Sitegeist.LostInTranslation.nodeTranslation.synchronization`. Reads as:
 * "When a publish lands on `sourceWorkspaceName`, auto-translate (`targetWorkspaceName`,
 * `targetDimension`) from `sourceDimension`."
 *
 * Automatic synchronization is always driven by the stale-translation projection. `scope` picks
 * how far the mirroring reaches: {@see SynchronizationScope::Content} only fills in content below
 * Documents that already exist in the target, while {@see SynchronizationScope::Document} also
 * creates the missing Document variants themselves. Walking the whole tree from the root is the
 * separate `synchronize --full` CLI command, never triggered automatically.
 */
#[Flow\Proxy(false)]
final readonly class SynchronizationRule
{
    public function __construct(
        public string $sourceWorkspaceName,
        public string $sourceDimension,
        public string $targetWorkspaceName,
        public string $targetDimension,
        public SynchronizationScope $scope,
    ) {
    }

    /**
     * @param array<string,string> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['sourceWorkspaceName', 'sourceDimension', 'targetWorkspaceName', 'targetDimension'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
                throw new \InvalidArgumentException(sprintf('SynchronizationRule is missing required string field "%s"', $key), 1779051200);
            }
        }

        if (!isset($row['scope']) || !is_string($row['scope']) || $row['scope'] === '') {
            throw new \InvalidArgumentException('SynchronizationRule is missing required string field "scope"', 1779051201);
        }
        $scope = SynchronizationScope::tryFrom($row['scope']);
        if ($scope === null) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid scope "%s" in SynchronizationRule; expected one of: %s',
                $row['scope'],
                implode(', ', array_map(static fn (SynchronizationScope $s): string => $s->value, SynchronizationScope::cases())),
            ), 1779051202);
        }

        return new self(
            sourceWorkspaceName: $row['sourceWorkspaceName'],
            sourceDimension: $row['sourceDimension'],
            targetWorkspaceName: $row['targetWorkspaceName'],
            targetDimension: $row['targetDimension'],
            scope: $scope,
        );
    }
}
