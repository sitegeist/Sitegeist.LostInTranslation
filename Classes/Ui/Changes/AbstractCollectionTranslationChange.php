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
        return $this->subject->getNodeType()->isOfType('Neos.Neos:ContentCollection') || $this->subject->getNodeType()->isOfType('Neos.Neos:Document') ;
    }

    protected function getComparisonResult(NodeInterface $node): ?Result
    {
        $referenceDimensionValues = [$this->languageDimensionName => [$this->referenceLanguage]];

        $referenceContentContext = $this->createContentContext($node->getContext()->getWorkspaceName(), $referenceDimensionValues);
        $referenceCollectionNode = $referenceContentContext->getNodeByIdentifier($node->getIdentifier());
        if ($referenceCollectionNode === null) {
            return null;
        }
        if ($node->getNodeType()->isOfType('Neos.Neos:Document')) {
            return $this->comparator->compareDocumentNode($node, $referenceCollectionNode);
        } elseif ($node->getNodeType()->isOfType('Neos.Neos:ContentCollection')) {
            return $this->comparator->compareCollectionNode($node, $referenceCollectionNode);
        } else {
            return null;
        }
    }
}
