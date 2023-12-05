<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\CollectionComparison;

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

        $reduceToArrayWithIdentifier = function (array $carry, NodeInterface $item) {
            $carry[$item->getIdentifier()] = $item;
            return $carry;
        };

        // ensure deleted but not yet published nodes are found aswell so we will not try to translate those
        $currentContextProperties = $currentNode->getContext()->getProperties();
        $currentContextProperties['removedContentShown'] = true;
        $currentContextIncludingRemovedItems = $this->contextFactory->create($currentContextProperties);
        $currentNodeInContextShowingRemovedItems = $currentContextIncludingRemovedItems->getNodeByIdentifier($currentNode->getIdentifier());
        if (is_null($currentNodeInContextShowingRemovedItems)) {
            return $result;
        }

        /**
         * @var NodeInterface[] $currentCollectionChildren
         */
        $currentCollectionChildren = array_reduce($currentNodeInContextShowingRemovedItems->getChildNodes(), $reduceToArrayWithIdentifier, []);
        $currentCollectionChildrenIdentifiers = array_keys($currentCollectionChildren);

        /**
         * @var NodeInterface[] $referenceCollectionChildren
         */
        $referenceCollectionChildren = array_reduce($referenceNode->getChildNodes(), $reduceToArrayWithIdentifier, []);
        $referenceCollectionChildrenIdentifiers =  array_keys($referenceCollectionChildren);

        /**
         * @var MissingNodeReference[] $result
         */
        $missing = [];
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
        if (count($missing) > 0) {
            $result = $result->withMissingNodes(...$missing);
        }

        /**
         * @var MissingNodeReference[] $result
         */
        $outdated = [];
        foreach ($currentCollectionChildren as $identifier => $currentCollectionCollectionChild) {
            if (array_key_exists($identifier, $referenceCollectionChildren)
                && $referenceCollectionChildren[$identifier]->getNodeData()->getLastModificationDateTime() > $currentCollectionCollectionChild->getNodeData()->getLastModificationDateTime()
            ) {
                $outdated[] = new OutdatedNodeReference(
                    $currentCollectionCollectionChild,
                    $referenceCollectionChildren[$identifier]
                );
            }
        }
        if (count($outdated) > 0) {
            $result = $result->withOutdatedNodes(...$outdated);
        }

        return $result;
    }
}
