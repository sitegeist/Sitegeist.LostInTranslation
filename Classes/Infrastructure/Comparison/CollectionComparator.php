<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\Comparison;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Sitegeist\LostInTranslation\Domain\Comparison\NodeInformation;
use Sitegeist\LostInTranslation\Domain\Comparison\Result;

class CollectionComparator
{
    public static function compareCollectionNode(NodeInterface $currentNode, NodeInterface $referenceNode): Result
    {
        $reduceToArrayWithIdentifier = function (array $carry, NodeInterface $item) {
            $carry[$item->getIdentifier()] = $item;
            return $carry;
        };

        /**
         * @var NodeInterface[] $currentCollectionChildren
         */
        $currentCollectionChildren = array_reduce($currentNode->getChildNodes(), $reduceToArrayWithIdentifier, []);
        $currentCollectionChildrenIdentifiers = array_values($currentCollectionChildren);

        /**
         * @var NodeInterface[] $referenceCollectionChildren
         */
        $referenceCollectionChildren = array_reduce($referenceNode->getChildNodes(), $reduceToArrayWithIdentifier, []);
        $referenceCollectionChildrenIdentifiers =  array_values($referenceCollectionChildren);

        $result = Result::createEmpty();
        /**
         * @var NodeInformation[] $result
         */
        $missing = [];
        foreach ($referenceCollectionChildren as $identifier => $referenceCollectionChild) {

            if (!array_key_exists($identifier, $currentCollectionChildren)) {
                $position = array_search($identifier, $referenceCollectionChildrenIdentifiers);
                $previousIdentifier = ($position !== false && array_key_exists($position - 1, $referenceCollectionChildrenIdentifiers)) ? $referenceCollectionChildrenIdentifiers[$position - 1] : null;
                $nextIdentifier =  ($position !== false && array_key_exists($position + 1, $referenceCollectionChildrenIdentifiers)) ? $referenceCollectionChildrenIdentifiers[$position + 1] : null;

                $missing[] = new NodeInformation(
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
         * @var NodeInformation[] $result
         */
        $outdated = [];
        foreach ($currentCollectionChildren as $identifier => $currentCollectionCollectionChild) {
            if (array_key_exists($identifier, $referenceCollectionChildren)
                && $referenceCollectionChildren[$identifier]->getNodeData()->getLastModificationDateTime() > $currentCollectionCollectionChild->getNodeData()->getLastModificationDateTime()
            ) {
                $position = array_search($identifier, $currentCollectionChildrenIdentifiers);
                $previousIdentifier = ($position !== false && array_key_exists($position - 1, $currentCollectionChildrenIdentifiers)) ? $currentCollectionChildrenIdentifiers[$position - 1] : null;
                $nextIdentifier = ($position !== false && array_key_exists($position + 1, $currentCollectionChildrenIdentifiers)) ? $currentCollectionChildrenIdentifiers[$position + 1] : null;

                $outdated[] = new NodeInformation(
                    $currentCollectionCollectionChild,
                    $previousIdentifier,
                    $nextIdentifier
                );
            }
        }
        if (count($outdated) > 0) {
            $result = $result->withOutdatedNodes(...$outdated);
        }

        return $result;
    }
}
