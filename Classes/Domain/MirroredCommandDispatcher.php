<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Neos\Domain\SubtreeTagging\NeosSubtreeTag;

/**
 * Dispatches the subtree-tag commands {@see TargetTagReconciler} collected and turns them into one
 * {@see PerNodeSynchronizationResult} per affected node aggregate. Shared by the manual "sync now"
 * ({@see WorkspaceSynchronizer}) and the CLI full sync ({@see FullWorkspaceSynchronizer}) so both record and dispatch
 * identically.
 *
 * Commands carrying the `removed` tag are counted as **removals** rather than tag changes. In Neos 9.1 that tag *is* the
 * deletion (a soft removal, later turned into a hard one per dimension by Neos's `SoftRemovalGarbageCollector`), so
 * reporting it as "1 tag change" would tell an editor who deleted a page something they would not recognise. Untagging
 * `removed` — a restore from the trash bin — is counted the same way, since it is the same kind of structural event.
 *
 * On a dry run the would-be result is still recorded (so the CLI can preview which nodes WOULD be removed/tagged), but
 * nothing is dispatched.
 */
final class MirroredCommandDispatcher
{
    /**
     * @param list<TagSubtree|UntagSubtree> $commands each addressing a target node aggregate
     * @return list<PerNodeSynchronizationResult>
     */
    public static function dispatch(
        ContentRepository $contentRepository,
        AiCommandDispatcher $aiCommandDispatcher,
        array $commands,
        bool $dryRun,
    ): array {
        $removedTag = NeosSubtreeTag::removed();
        // Keyed by aggregate id for grouping, but carrying the id's value object rather than re-hydrating it from the
        // array key (PHP would coerce a numeric-looking id to int).
        /** @var array<string,array{id:NodeAggregateId,removals:int,tagChanges:int}> $perNode */
        $perNode = [];
        foreach ($commands as $command) {
            $key = $command->nodeAggregateId->value;
            if (!isset($perNode[$key])) {
                $perNode[$key] = ['id' => $command->nodeAggregateId, 'removals' => 0, 'tagChanges' => 0];
            }
            if ($command->tag->equals($removedTag)) {
                $perNode[$key]['removals']++;
            } else {
                $perNode[$key]['tagChanges']++;
            }
            if (!$dryRun) {
                $aiCommandDispatcher->dispatch($contentRepository, $command);
            }
        }

        $results = [];
        foreach ($perNode as $entry) {
            $results[] = new PerNodeSynchronizationResult(
                $entry['id'],
                RetranslationResult::mirrored($entry['removals'], $entry['tagChanges']),
            );
        }
        return $results;
    }
}
