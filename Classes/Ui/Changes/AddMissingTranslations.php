<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Ui\Changes;


use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Info;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\ReloadDocument;

class AddMissingTranslations extends AbstractCollectionTranslationChange
{

    public function apply()
    {
        $collection = $this->subject;
        $comparisonResult = $this->getComparisonResult();
        if (is_null($comparisonResult)) {
            return;
        }

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

//        $feedback = new ReloadDocument();
//        $feedback->setNode();

        $info = new Info();
        $info->setMessage('Missing Nodes were added');

        $this->feedbackCollection->add($info);
    }
}
