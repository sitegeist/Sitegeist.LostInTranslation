<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;

/**
 * Distance of a node from its root aggregate in a subgraph (root = 0, its children = 1, …).
 *
 * Used to order synchronization commands ancestor-before-descendant, so a parent variant is created before any
 * descendant's `CreateNodeVariant` (which requires the parent to already cover the target dimension).
 */
final class NodeTreeDepth
{
    public static function of(ContentSubgraphInterface $subgraph, NodeAggregateId $nodeAggregateId): int
    {
        return $subgraph->findAncestorNodes($nodeAggregateId, FindAncestorNodesFilter::create())->count();
    }
}
