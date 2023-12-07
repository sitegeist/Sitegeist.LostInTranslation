<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Ui\Changes;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Ui\Domain\Model\AbstractChange;
use Neos\Neos\Ui\Domain\Model\FeedbackCollection;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Comparator;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Result;

abstract class AbstractCollectionTranslationChange extends AbstractChange
{
    use CreateContentContextTrait;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\Inject
     * @var Comparator
     */
    protected $comparator;

    /**
     * @Flow\Inject
     * @var FeedbackCollection
     */
    protected $feedbackCollection;

    /**
     * @var string
     */
    protected $referenceLanguage;

    public function setReferenceLanguage(string $referenceLanguage): void
    {
        $this->referenceLanguage = $referenceLanguage;
    }

    public function canApply()
    {
        return $this->subject->getNodeType()->isOfType('Neos.Neos:ContentCollection');
    }

    protected function getComparisonResult(): ?Result
    {
        $referenceDimensionValues = [$this->languageDimensionName => [$this->referenceLanguage]];
        $currentCollection = $this->subject;
        $referenceContentContext = $this->createContentContext($currentCollection->getContext()->getWorkspaceName(), $referenceDimensionValues);
        $referenceCollectionNode = $referenceContentContext->getNodeByIdentifier($currentCollection->getIdentifier());
        if ($referenceCollectionNode === null) {
            return null;
        }
        $comparisonResult = $this->comparator->compareCollectionNode($currentCollection, $referenceCollectionNode);
        return $comparisonResult;
    }
}
