<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Reconciles deletions for the deliberate (non publish-driven) synchronization runs — the manual "sync now"
 * ({@see WorkspaceSynchronizer}) and the CLI full sync ({@see FullWorkspaceSynchronizer}). Where the publish-driven
 * {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook} mirrors a deletion
 * incrementally from the publish's own removal events, these runs are decoupled from the publish and so the events are
 * gone — they instead reconcile by DIFFING the target dimension against the source: every node present in the target
 * dimension whose aggregate has no variant in the source dimension is an orphan to remove.
 *
 * This also self-heals deletions that happened before {@see SourceRemovalBehavior::RemoveTarget} was enabled.
 *
 * Removal is gated by {@see SynchronizationScope::mayRemoveNode()} — under {@see SynchronizationScope::Content} a
 * Document orphan is kept (symmetric with never auto-creating Documents), but the run still descends into it to remove
 * orphaned content beneath it. Removing a non-Document orphan stops the descent: the Content Repository cascades the
 * removal of its descendants in the target dimension.
 */
final class TargetOrphanCollector
{
    /**
     * @return list<RemoveNodeAggregate> top-down, one per orphan subtree-root that may be removed under the scope
     */
    public static function collect(
        ContentGraphInterface $targetContentGraph,
        ContentSubgraphInterface $targetSubgraph,
        ContentSubgraphInterface $sourceSubgraph,
        NodeTypeManager $nodeTypeManager,
        SynchronizationScope $scope,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): array {
        $commands = [];
        foreach ($targetContentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            $rootNode = $targetSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            // Root aggregates have no source/target counterpart to diff (they are dimension-agnostic structure), so we
            // never remove them — only walk their descendants.
            self::collectBelow(
                $rootNode,
                $targetSubgraph,
                $sourceSubgraph,
                $nodeTypeManager,
                $scope,
                $targetWorkspaceName,
                $targetDimensionSpacePoint,
                $commands,
            );
        }
        return $commands;
    }

    /**
     * @param list<RemoveNodeAggregate> $commands
     */
    private static function collectBelow(
        Node $node,
        ContentSubgraphInterface $targetSubgraph,
        ContentSubgraphInterface $sourceSubgraph,
        NodeTypeManager $nodeTypeManager,
        SynchronizationScope $scope,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        array &$commands,
    ): void {
        foreach ($targetSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $child) {
            // Tethered nodes (e.g. a Document's ContentCollection) cannot be removed independently — they only
            // disappear with their parent's removal. Never emit a removal for one, but still descend to reconcile the
            // content beneath it.
            if ($child->classification->isTethered()) {
                self::collectBelow($child, $targetSubgraph, $sourceSubgraph, $nodeTypeManager, $scope, $targetWorkspaceName, $targetDimensionSpacePoint, $commands);
                continue;
            }
            $isOrphan = $sourceSubgraph->findNodeById($child->aggregateId) === null;
            if ($isOrphan) {
                $nodeType = $nodeTypeManager->getNodeType($child->nodeTypeName);
                $isDocument = $nodeType !== null && $nodeType->isOfType('Neos.Neos:Document');
                if ($scope->mayRemoveNode($isDocument)) {
                    $commands[] = RemoveNodeAggregate::create(
                        $targetWorkspaceName,
                        $child->aggregateId,
                        $targetDimensionSpacePoint,
                        // The target DSP and its specializations — the CR cascades descendant removal, so we do not
                        // recurse into a removed subtree.
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                    );
                    continue;
                }
                // A Document orphan kept under Content scope: leave it, but its content descendants may still be
                // orphaned and removable, so keep descending.
            }
            self::collectBelow($child, $targetSubgraph, $sourceSubgraph, $nodeTypeManager, $scope, $targetWorkspaceName, $targetDimensionSpacePoint, $commands);
        }
    }
}
