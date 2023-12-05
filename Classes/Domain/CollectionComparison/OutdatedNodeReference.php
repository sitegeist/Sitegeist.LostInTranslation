<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\CollectionComparison;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class OutdatedNodeReference
{
    protected NodeInterface $node;
    protected NodeInterface $referenceNode;

    public function __construct (NodeInterface $node, NodeInterface $referenceNode)
    {
        $this->node = $node;
        $this->referenceNode = $referenceNode;
    }

    public function getNode(): NodeInterface
    {
        return $this->node;
    }

    public function getReferenceNode(): NodeInterface
    {
        return $this->referenceNode;
    }
}
