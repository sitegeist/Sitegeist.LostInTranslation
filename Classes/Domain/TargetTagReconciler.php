<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;

/**
 * Reconciles subtree tags (the `disabled` hide/show tag and ANY other {@see SubtreeTag}) for the deliberate (non
 * publish-driven) synchronization runs — the manual "sync now" ({@see WorkspaceSynchronizer}) and the CLI
 * ({@see FullWorkspaceSynchronizer}). The publish-driven {@see
 * \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook} mirrors tag changes
 * incrementally from the publish's own `SubtreeWasTagged` / `SubtreeWasUntagged` events; these runs are decoupled from
 * the publish, so they instead DIFF each node's EXPLICIT tag set in the target dimension against the source dimension
 * and converge the target onto the source.
 *
 * Diffing per node against the source's explicit tags makes the run idempotent by construction — a `TagSubtree` is only
 * emitted for a tag the target lacks, an `UntagSubtree` only for a tag the target has — so the CR never trips
 * `SubtreeIsAlreadyTagged` / `SubtreeIsNotTagged`. Only EXPLICIT tags are mirrored (inherited ones reproduce themselves
 * once their explicitly-tagged ancestor is reconciled), reproducing the source's tag STRUCTURE, not just its effective
 * state. Not gated by {@see SynchronizationScope}: a hidden Document hides in the target whether the rule mirrors
 * structure or only content.
 *
 * The subgraphs are read fresh from the content graphs (with restrictions lifted, so disabled nodes are still visible)
 * so a target variant created earlier in the same run — and therefore initially untagged — is picked up here.
 */
final class TargetTagReconciler
{
    /**
     * @return list<TagSubtree|UntagSubtree>
     */
    public static function collect(
        ContentGraphInterface $targetContentGraph,
        ContentGraphInterface $sourceContentGraph,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        DimensionSpacePoint $targetDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
    ): array {
        $targetSubgraph = $targetContentGraph->getSubgraph(
            $targetDimensionSpacePoint,
            VisibilityConstraints::withoutRestrictions(),
        );
        $sourceSubgraph = $sourceContentGraph->getSubgraph(
            $sourceDimensionSpacePoint,
            VisibilityConstraints::withoutRestrictions(),
        );

        $commands = [];
        foreach ($targetContentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            $rootNode = $targetSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            // Root aggregates are dimension-agnostic structure with no source/target counterpart to diff — only walk
            // their descendants.
            self::reconcileBelow($rootNode, $targetSubgraph, $sourceSubgraph, $targetWorkspaceName, $targetDimensionSpacePoint, $commands);
        }
        return $commands;
    }

    /**
     * @param list<TagSubtree|UntagSubtree> $commands
     */
    private static function reconcileBelow(
        Node $node,
        ContentSubgraphInterface $targetSubgraph,
        ContentSubgraphInterface $sourceSubgraph,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        array &$commands,
    ): void {
        foreach ($targetSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $child) {
            $sourceNode = $sourceSubgraph->findNodeById($child->aggregateId);
            // Only reconcile nodes present in both dimensions. A target-only orphan has no source tags to mirror (and
            // is the removal reconcile's concern); a source-only node has no target variant to tag.
            if ($sourceNode !== null) {
                $sourceTags = $sourceNode->tags->withoutInherited()->toStringArray();
                $targetTags = $child->tags->withoutInherited()->toStringArray();
                foreach (array_diff($sourceTags, $targetTags) as $tagToAdd) {
                    $commands[] = TagSubtree::create(
                        $targetWorkspaceName,
                        $child->aggregateId,
                        $targetDimensionSpacePoint,
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                        SubtreeTag::fromString($tagToAdd),
                    );
                }
                foreach (array_diff($targetTags, $sourceTags) as $tagToRemove) {
                    $commands[] = UntagSubtree::create(
                        $targetWorkspaceName,
                        $child->aggregateId,
                        $targetDimensionSpacePoint,
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                        SubtreeTag::fromString($tagToRemove),
                    );
                }
            }
            self::reconcileBelow($child, $targetSubgraph, $sourceSubgraph, $targetWorkspaceName, $targetDimensionSpacePoint, $commands);
        }
    }
}
