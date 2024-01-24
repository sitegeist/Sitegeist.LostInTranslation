<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Ui\Changes;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Success;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\ReloadDocument;
use Neos\Neos\Ui\Domain\Model\Feedback\Operations\UpdateWorkspaceInfo;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

class UpdateOutdatedTranslations extends AbstractCollectionTranslationChange
{
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

    public function apply()
    {

        $collection = $this->subject;

        $comparisonResult = $this->getComparisonResult($collection);

        if (is_null($comparisonResult)) {
            return;
        }

        $count = 0;
        foreach ($comparisonResult->getOutdated() as $outdatedNodeDifference) {
            $node = $outdatedNodeDifference->getNode();
            $referenceNode = $outdatedNodeDifference->getReferenceNode();
            $translatableProperties = $this->translatablePropertiesFactory->createForNodeType($referenceNode->getNodeType());
            $propertiesToTranslate = [];
            foreach ($translatableProperties as $translatableProperty) {
                $name = $translatableProperty->getName();
                $value = $referenceNode->getProperty($name);
                if (
                    is_string($value)
                    && (!empty($value) || !empty($node->getProperty($name) ?: ''))
                ) {
                    $propertiesToTranslate[$name] = $value;
                }
            }
            if (count($propertiesToTranslate) > 0) {
                $translatedProperties = $this->translationService->translate($propertiesToTranslate, $node->getContext()->getTargetDimensions()[$this->languageDimensionName], $referenceNode->getContext()->getTargetDimensions()[$this->languageDimensionName]);
                foreach ($translatedProperties as $propertyName => $propertyValue) {
                    if ($node->getProperty($propertyName) != $propertyValue) {
                        $node->setProperty($propertyName, $propertyValue);
                    }
                }
                $count++;
            }
        }

        $info = new Success();
        $info->setMessage($count . ' outdated nodes were updated');
        $this->feedbackCollection->add($info);

        $updateWorkspaceInfo = new UpdateWorkspaceInfo();
        $updateWorkspaceInfo->setWorkspace(
            $collection->getContext()->getWorkspace()
        );
        $this->feedbackCollection->add($updateWorkspaceInfo);

        $reload = new ReloadDocument();
        $this->feedbackCollection->add($reload);
    }
}
