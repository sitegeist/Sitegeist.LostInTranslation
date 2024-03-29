<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\CollectionComparison;

use Neos\ContentRepository\Domain\Model\Node;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class MissingNodeReference
{
    protected NodeInterface $node;

    protected NodeInterface $referenceNode;
    protected ?string $previousIdentifier;
    protected ?string $nextIdentifier;

    public function __construct(NodeInterface $node, NodeInterface $referenceNode, ?string $previousIdentifier, ?string $nextIdentifier)
    {
        $this->node = $node;
        $this->referenceNode = $referenceNode;
        $this->previousIdentifier = $previousIdentifier;
        $this->nextIdentifier = $nextIdentifier;
    }

    public function getNode(): NodeInterface
    {
        return $this->node;
    }

    public function getReferenceNode(): NodeInterface
    {
        return $this->referenceNode;
    }

    public function getPreviousIdentifier(): ?string
    {
        return $this->previousIdentifier;
    }

    public function getNextIdentifier(): ?string
    {
        return $this->nextIdentifier;
    }
}
