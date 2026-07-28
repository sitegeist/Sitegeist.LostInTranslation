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
 * Document orphan is kept (symmetric with never auto-creating Documents) and its whole subtree is left alone, matching
 * what the incremental hook path leaves behind. Removing a non-Document orphan likewise stops the descent: the Content
 * Repository cascades the removal of its descendants in the target dimension. A Document that exists in BOTH dimensions
 * is not an orphan, so the run descends into it as usual and still reconciles content removed inside it.
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
                // A Document orphan kept under Content scope: leave the whole subtree alone. The diff cannot tell
                // "removed in the source" from "never in the source" — under Content scope a target-only Document is an
                // expected, editor-owned page (the scope exists precisely because adopting a Document is a manual act),
                // and every content node below such a page is target-only too. Descending would delete all of it. Not
                // descending also matches what the event-driven hook path leaves behind: it skips the Document under
                // this scope and the CR emits no events for the cascade-removed content, so nothing below is touched.
                continue;
            }
            self::collectBelow($child, $targetSubgraph, $sourceSubgraph, $nodeTypeManager, $scope, $targetWorkspaceName, $targetDimensionSpacePoint, $commands);
        }
    }
}
