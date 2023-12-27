<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\CollectionComparison;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Neos\Domain\Service\ContentContextFactory;

class Comparator
{
    /**
     * @var ContentContextFactory
     * @Flow\Inject
     */
    protected $contextFactory;

    public function compareCollectionNode(NodeInterface $currentNode, NodeInterface $referenceNode): Result
    {
        $result = Result::createEmpty();
        /**
         * @var MissingNodeReference[] $missing
         */
        $missing = [];
        /**
         * @var OutdatedNodeReference[] $outdated
         */
        $outdated = [];

        // ensure deleted but not yet published nodes are found aswell so we will not try to translate those
        $currentContextProperties = $currentNode->getContext()->getProperties();
        $currentContextProperties['removedContentShown'] = true;
        $currentContextIncludingRemovedItems = $this->contextFactory->create($currentContextProperties);

        $this->traverseContentCollectionForAlteredNodes(
            $currentNode,
            $referenceNode,
            $currentContextIncludingRemovedItems,
            $missing,
            $outdated
        );

        if (count($missing) > 0) {
            $result = $result->withMissingNodes(...$missing);
        }

        if (count($outdated) > 0) {
            $result = $result->withOutdatedNodes(...$outdated);
        }

        return $result;
    }

    public function compareDocumentNode(NodeInterface $currentNode, NodeInterface $referenceNode): Result
    {
        $result = Result::createEmpty();
        /**
         * @var MissingNodeReference[] $missing
         */
        $missing = [];
        /**
         * @var OutdatedNodeReference[] $outdated
         */
        $outdated = [];

        // ensure deleted but not yet published nodes are found as well, so we will not try to translate those
        $currentContextProperties = $currentNode->getContext()->getProperties();
        $currentContextProperties['removedContentShown'] = true;
        $currentContextIncludingRemovedItems = $this->contextFactory->create($currentContextProperties);

        $referenceContentContext = $referenceNode->getContext();

        if ($referenceNode->getNodeData()->getLastModificationDateTime() > $currentNode->getNodeData()->getLastModificationDateTime()) {
            $outdated[] = new OutdatedNodeReference(
                $currentNode,
                $referenceNode
            );
        }

        foreach ($currentNode->getChildNodes() as $currentCollectionChild) {
            $referenceCollectionChild = $referenceContentContext->getNodeByIdentifier($currentCollectionChild->getIdentifier());
            if ($referenceCollectionChild) {
                $this->traverseContentCollectionForAlteredNodes(
                    $currentCollectionChild,
                    $referenceCollectionChild,
                    $currentContextIncludingRemovedItems,
                    $missing,
                    $outdated
                );
            }
        }

        if (count($missing) > 0) {
            $result = $result->withMissingNodes(...$missing);
        }

        if (count($outdated) > 0) {
            $result = $result->withOutdatedNodes(...$outdated);
        }

        return $result;
    }

    private function traverseContentCollectionForAlteredNodes(
        NodeInterface $currentNode,
        NodeInterface $referenceNode,
        Context $currentContextIncludingRemovedItems,
        array &$missing,
        array &$outdated,
    ): void {

        $reduceToArrayWithIdentifier = function (array $carry, NodeInterface $item) {
            $carry[$item->getIdentifier()] = $item;
            return $carry;
        };

        $currentNodeInContextShowingRemovedItems = $currentContextIncludingRemovedItems->getNodeByIdentifier($currentNode->getIdentifier());
        if (is_null($currentNodeInContextShowingRemovedItems)) {
            return;
        }

        /**
         * @var array<string,NodeInterface> $currentCollectionChildren
         */
        $currentCollectionChildren = array_reduce($currentNodeInContextShowingRemovedItems->getChildNodes(), $reduceToArrayWithIdentifier, []);

        /**
         * @var array<string,NodeInterface>  $referenceCollectionChildren
         */
        $referenceCollectionChildren = array_reduce($referenceNode->getChildNodes(), $reduceToArrayWithIdentifier, []);
        $referenceCollectionChildrenIdentifiers =  array_keys($referenceCollectionChildren);

        foreach ($referenceCollectionChildren as $identifier => $referenceCollectionChild) {
            if (!array_key_exists($identifier, $currentCollectionChildren)) {
                $position = array_search($identifier, $referenceCollectionChildrenIdentifiers);
                $previousIdentifier = ($position !== false && array_key_exists($position - 1, $referenceCollectionChildrenIdentifiers)) ? $referenceCollectionChildrenIdentifiers[$position - 1] : null;
                $nextIdentifier = ($position !== false && array_key_exists($position + 1, $referenceCollectionChildrenIdentifiers)) ? $referenceCollectionChildrenIdentifiers[$position + 1] : null;

                $missing[] = new MissingNodeReference(
                    $referenceCollectionChild,
                    $previousIdentifier,
                    $nextIdentifier
                );
            }
        }

        foreach ($currentCollectionChildren as $identifier => $currentCollectionCollectionChild) {
            if (
                array_key_exists($identifier, $referenceCollectionChildren)
                && $referenceCollectionChildren[$identifier]->getNodeData()->getLastModificationDateTime() > $currentCollectionCollectionChild->getNodeData()->getLastModificationDateTime()
            ) {
                $outdated[] = new OutdatedNodeReference(
                    $currentCollectionCollectionChild,
                    $referenceCollectionChildren[$identifier]
                );
            }

            if ($currentCollectionCollectionChild->hasChildNodes() && array_key_exists($identifier, $referenceCollectionChildren)) {
                $this->traverseContentCollectionForAlteredNodes(
                    $currentCollectionCollectionChild,
                    $referenceCollectionChildren[$identifier],
                    $currentContextIncludingRemovedItems,
                    $missing,
                    $outdated
                );
            }
        }
    }
}
