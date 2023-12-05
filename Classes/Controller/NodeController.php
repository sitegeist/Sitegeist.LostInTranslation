<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Neos\Controller\CreateContentContextTrait;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Comparator;

class NodeController extends ActionController
{
    use CreateContentContextTrait;

    /**
     * @Flow\Inject
     * @var PersistenceManager
     */
    protected $persistenceManager;

    /**
     * @Flow\Inject
     * @var Comparator
     */
    protected $comparator;

    public function addMissingTranslationsAction(NodeInterface $document, NodeInterface $collection, string $referenceDimensions): void
    {
        $referenceDimensionsArray = json_decode($referenceDimensions, true);

        if (!is_array($referenceDimensionsArray)) {
            $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
        }

        $referenceContentContext = $this->createContentContext($collection->getContext()->getWorkspaceName(), $referenceDimensionsArray);
        $referenceCollectionNode = $referenceContentContext->getNodeByIdentifier($collection->getIdentifier());
        if ($referenceCollectionNode === null) {
            $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
        }

        $comparisonResult = $this->comparator->compareCollectionNode($collection, $referenceCollectionNode);
        foreach ($comparisonResult->getMissing() as $missingNodeDifference) {
            $adoptedNode = $collection->getContext()->adoptNode($missingNodeDifference->getNode(), true);
            if ($missingNodeDifference->getPreviousIdentifier()) {
                $previousNode = $collection->getContext()->getNodeByIdentifier($missingNodeDifference->getPreviousIdentifier());
                if ($previousNode && $previousNode->getParent() === $adoptedNode->getParent()) {
                    $adoptedNode->moveAfter($previousNode);
                }
            }
            if ($missingNodeDifference->getNextIdentifier()) {
                $nextNode = $collection->getContext()->getNodeByIdentifier($missingNodeDifference->getNextIdentifier());
                if ($nextNode && $nextNode->getParent() === $adoptedNode->getParent()) {
                    $adoptedNode->moveBefore($nextNode);
                }
            }
        }

        $this->persistenceManager->persistAll();
        $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
    }
}
