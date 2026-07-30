<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindDescendantNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Nodes;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
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
 *
 * There is no way to ask the subgraph for "the nodes carrying an explicit tag", so the diff has to visit the whole
 * target tree — which on an established site is the whole site, on every "sync now", however little changed. That scan
 * is inherent to a diff-based reconcile; what is not inherent is paying database round trips for it, so it costs a
 * handful of queries rather than two per node: one recursive CTE per root aggregate for the target side
 * ({@see ContentSubgraphInterface::findDescendantNodes()}), and batched id lookups for the source side.
 */
final class TargetTagReconciler
{
    /**
     * How many source counterparts to fetch per query. Doctrine expands `IN (:ids)` into one placeholder per id and
     * MySQL caps a prepared statement at 65535 of them, so a site-sized tree cannot go in a single call.
     */
    private const SOURCE_LOOKUP_BATCH_SIZE = 1000;

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
        // FULL visibility on both sides — `createEmpty()`, not `withoutRestrictions()`, which despite its name excludes
        // the `removed` tag. Soft-removed nodes are exactly what this reconcile has to see: a deleted source node must
        // still be readable for its `removed` tag to be mirrored, and a soft-removed TARGET node must still be reachable
        // so it can be untagged when the source is restored from the trash bin.
        $targetSubgraph = $targetContentGraph->getSubgraph(
            $targetDimensionSpacePoint,
            VisibilityConstraints::createEmpty(),
        );
        $sourceSubgraph = $sourceContentGraph->getSubgraph(
            $sourceDimensionSpacePoint,
            VisibilityConstraints::createEmpty(),
        );

        $commands = [];
        foreach ($targetContentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            // Root aggregates are dimension-agnostic structure with no source/target counterpart to diff — only their
            // descendants are reconciled, and one `findDescendantNodes` returns all of them. No separate check that the
            // root itself is visible here: an entry node this subgraph cannot see simply yields no descendants.
            $targetNodes = $targetSubgraph->findDescendantNodes(
                $rootAggregate->nodeAggregateId,
                FindDescendantNodesFilter::create(),
            );
            $sourceNodesById = self::sourceCounterpartsById($sourceSubgraph, $targetNodes);
            foreach ($targetNodes as $targetNode) {
                // Only reconcile nodes present in both dimensions. A target-only orphan has no source tags to mirror
                // (and is the removal reconcile's concern); a source-only node has no target variant to tag. Its
                // descendants are still reconciled — a flat list reaches them regardless, exactly as the tree walk this
                // replaced did by recursing past a missing counterpart rather than pruning there.
                $sourceNode = $sourceNodesById[$targetNode->aggregateId->value] ?? null;
                if ($sourceNode === null) {
                    continue;
                }
                $sourceTags = $sourceNode->tags->withoutInherited()->toStringArray();
                $targetTags = $targetNode->tags->withoutInherited()->toStringArray();
                foreach (array_diff($sourceTags, $targetTags) as $tagToAdd) {
                    $commands[] = TagSubtree::create(
                        $targetWorkspaceName,
                        $targetNode->aggregateId,
                        $targetDimensionSpacePoint,
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                        SubtreeTag::fromString($tagToAdd),
                    );
                }
                foreach (array_diff($targetTags, $sourceTags) as $tagToRemove) {
                    $commands[] = UntagSubtree::create(
                        $targetWorkspaceName,
                        $targetNode->aggregateId,
                        $targetDimensionSpacePoint,
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                        SubtreeTag::fromString($tagToRemove),
                    );
                }
            }
        }
        return $commands;
    }

    /**
     * The source-dimension counterparts of `$targetNodes`, indexed by aggregate id — the batched form of one
     * `findNodeById` per target node, and identical to it in what it finds: `findNodesByIds` applies the same subgraph
     * constraints and simply returns whichever of the ids exist here.
     *
     * @return array<string,Node>
     */
    private static function sourceCounterpartsById(ContentSubgraphInterface $sourceSubgraph, Nodes $targetNodes): array
    {
        $targetNodeIds = [];
        foreach ($targetNodes as $targetNode) {
            $targetNodeIds[] = $targetNode->aggregateId->value;
        }
        $sourceNodesById = [];
        foreach (array_chunk($targetNodeIds, self::SOURCE_LOOKUP_BATCH_SIZE) as $idBatch) {
            foreach ($sourceSubgraph->findNodesByIds(NodeAggregateIds::fromArray($idBatch)) as $sourceNode) {
                $sourceNodesById[$sourceNode->aggregateId->value] = $sourceNode;
            }
        }
        return $sourceNodesById;
    }
}
