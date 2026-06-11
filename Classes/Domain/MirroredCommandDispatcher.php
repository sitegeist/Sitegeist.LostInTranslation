<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * Dispatches the commands a deletion / tag reconcile collected ({@see TargetOrphanCollector}, {@see TargetTagReconciler})
 * and turns them into one {@see PerNodeSynchronizationResult} per affected node aggregate. Shared by the manual
 * "sync now" ({@see WorkspaceSynchronizer}) and the CLI full sync ({@see FullWorkspaceSynchronizer}) so both record and
 * dispatch removals/tags identically.
 *
 * On a dry run the would-be result is still recorded (so the CLI can preview which nodes WOULD be removed/tagged), but
 * nothing is dispatched.
 */
final class MirroredCommandDispatcher
{
    /**
     * @param list<RemoveNodeAggregate|TagSubtree|UntagSubtree> $commands each addressing a target node aggregate
     * @param \Closure(int $commandCount): RetranslationResult $makeResult builds the per-node result from the number
     *        of commands dispatched against that node (e.g. one removal, or N tag changes)
     * @return list<PerNodeSynchronizationResult>
     */
    public static function dispatch(
        ContentRepository $contentRepository,
        AiCommandDispatcher $aiCommandDispatcher,
        array $commands,
        bool $dryRun,
        \Closure $makeResult,
    ): array {
        /** @var array<string,int> $commandCountByNode */
        $commandCountByNode = [];
        foreach ($commands as $command) {
            $commandCountByNode[$command->nodeAggregateId->value] = ($commandCountByNode[$command->nodeAggregateId->value] ?? 0) + 1;
            if (!$dryRun) {
                $aiCommandDispatcher->dispatch($contentRepository, $command);
            }
        }

        $results = [];
        foreach ($commandCountByNode as $nodeAggregateIdValue => $commandCount) {
            $results[] = new PerNodeSynchronizationResult(
                NodeAggregateId::fromString((string)$nodeAggregateIdValue),
                $makeResult($commandCount),
            );
        }
        return $results;
    }
}
