<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Neos\Controller\CreateContentContextTrait;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Comparator;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Result;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

class CollectionTranslationController extends ActionController
{
    use CreateContentContextTrait;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

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

    /**
     * @Flow\Inject
     * @var TranslatablePropertyNamesFactory
     */
    protected $translatablePropertiesFactory;

    /**
     * @Flow\Inject
     * @var TranslationServiceInterface
     */
    protected $translationService;

    public function addMissingTranslationsAction(NodeInterface $document, NodeInterface $collection, string $referenceLanguage): void
    {
        $comparisonResult = $this->getComparisonResult($collection, [$this->languageDimensionName => [$referenceLanguage]]);
        if (is_null($comparisonResult)) {
            $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
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
        $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
    }


    public function updateOutdatedTranslationsAction(NodeInterface $document, NodeInterface $collection, string $referenceLanguage): void
    {
        $comparisonResult = $this->getComparisonResult($collection, [$this->languageDimensionName => [$referenceLanguage]]);
        if (is_null($comparisonResult)) {
            $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
        }

        foreach ($comparisonResult->getOutdated() as $outdatedNodeDifference) {
            $node = $outdatedNodeDifference->getNode();
            $referenceNode = $outdatedNodeDifference->getReferenceNode();
            $translatableProperties = $this->translatablePropertiesFactory->createForNodeType($referenceNode->getNodeType());
            $propertiesToTranslate = [];
            foreach ($translatableProperties as $translatableProperty) {
                $name = $translatableProperty->getName();
                $value = $referenceNode->getProperty($name);
                if (!empty($value) && is_string($value) && strip_tags($value) !== '') {
                    $propertiesToTranslate[$name] = $value;
                }
            }
            if (count($propertiesToTranslate) > 0) {
                $translatedProperties = $this->translationService->translate($propertiesToTranslate, $node->getContext()->getTargetDimensions()[$this->languageDimensionName], $referenceNode->getContext()->getTargetDimensions()[$this->languageDimensionName]);
            }

            foreach ($translatedProperties as $propertyName => $propertyValue) {
                if ($node->getProperty($propertyName) != $propertyValue) {
                    $node->setProperty($propertyName, $propertyValue);
                }
            }
        }

        $this->persistenceManager->persistAll();
        $this->redirect('preview', 'Frontend\Node', 'Neos.Neos', ['node' => $document]);
    }

    /**
     * @param NodeInterface $collection
     * @param array $referenceDimensions
     * @return Result
     * @throws \Neos\Flow\Mvc\Exception\StopActionException
     */
    protected function getComparisonResult(NodeInterface $collection, array $referenceDimensions): ?Result
    {
        $referenceContentContext = $this->createContentContext($collection->getContext()->getWorkspaceName(), $referenceDimensions);
        $referenceCollectionNode = $referenceContentContext->getNodeByIdentifier($collection->getIdentifier());
        if ($referenceCollectionNode === null) {
            return null;
        }

        $comparisonResult = $this->comparator->compareCollectionNode($collection, $referenceCollectionNode);
        return $comparisonResult;
    }
}
