<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * One rule of `Sitegeist.LostInTranslation.nodeTranslation.synchronization`. Reads as: "When a publish lands on
 * `sourceWorkspaceName`, auto-translate (`targetWorkspaceName`, `targetDimension`) from `sourceDimension`."
 *
 * A rule has exactly two knobs, because the target dimension is a projection of the source: the source language is the
 * single source of truth for properties, structure and subtree tags, and target-side changes are overwritten by design.
 *
 *  - `scope` decides whether synchronization may CREATE Document variants: {@see SynchronizationScope::Content} only
 *    fills in content below Documents that already exist in the target, while {@see SynchronizationScope::Document} also
 *    creates the missing Document variants themselves. Walking the whole tree from the root is the separate
 *    `synchronize --full` CLI command, never triggered automatically.
 *  - `mode` decides when the TRANSLATION runs: inline during the publish ({@see SynchronizationMode::Auto}) or deferred
 *    to a deliberate "sync now" action in the Neos UI / backend module ({@see SynchronizationMode::Ask}). Subtree tags
 *    (incl. the `removed` soft-removal tag, i.e. deletions), mirrored hard removals and the cross-workspace rebase are
 *    applied inline on every publish for BOTH modes — only translating is deferred.
 *
 * Mirroring subtree tags and removals is unconditional; there is nothing to configure. The retired `onSourceRemoval` /
 * `onSourceTagging` keys are rejected by {@see self::fromArray()} rather than ignored, because their old default was
 * "off" and silently switching them on would start deleting content in the target dimension.
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
        public SynchronizationMode $mode = SynchronizationMode::Auto,
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

        // `mode` is optional and defaults to Auto (inline-on-publish), so existing rule configs keep working. A present
        // but unrecognized value is a configuration mistake and fails loudly rather than silently falling back.
        $mode = SynchronizationMode::Auto;
        if (isset($row['mode']) && $row['mode'] !== '') {
            if (!is_string($row['mode'])) {
                throw new \InvalidArgumentException('SynchronizationRule field "mode" must be a string', 1779051203);
            }
            $mode = SynchronizationMode::tryFrom($row['mode']);
            if ($mode === null) {
                throw new \InvalidArgumentException(sprintf(
                    'Invalid mode "%s" in SynchronizationRule; expected one of: %s',
                    $row['mode'],
                    implode(', ', array_map(static fn (SynchronizationMode $m): string => $m->value, SynchronizationMode::cases())),
                ), 1779051204);
            }
        }

        // The retired `onSourceRemoval` / `onSourceTagging` keys are REJECTED, not ignored. Both used to default to
        // "keep the target untouched", and mirroring is now unconditional — so silently accepting an old config would
        // start hiding and deleting nodes in the target dimension without the integrator ever asking for it. Fail the
        // configuration instead and let them re-read the docs.
        foreach (['onSourceRemoval', 'onSourceTagging'] as $retiredKey) {
            if (isset($row[$retiredKey])) {
                throw new \InvalidArgumentException(sprintf(
                    'SynchronizationRule field "%s" has been removed. Subtree tags (including the `removed` '
                    . 'soft-removal tag, i.e. deletions) and mirrored hard removals are now always synchronized, with '
                    . 'the source language as the single source of truth — the target dimension is a projection of it. '
                    . 'Remove the key from your rule; if you relied on the previous "keep-target" default, remove the '
                    . 'rule instead.',
                    $retiredKey,
                ), 1779051205);
            }
        }

        return new self(
            sourceWorkspaceName: $row['sourceWorkspaceName'],
            sourceDimension: $row['sourceDimension'],
            targetWorkspaceName: $row['targetWorkspaceName'],
            targetDimension: $row['targetDimension'],
            scope: $scope,
            mode: $mode,
        );
    }
}
