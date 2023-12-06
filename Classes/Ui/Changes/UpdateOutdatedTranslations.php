<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Ui\Changes;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Ui\Domain\Model\Feedback\Messages\Info;
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
        $comparisonResult = $this->getComparisonResult();
        if (is_null($comparisonResult)) {
            return;
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
                foreach ($translatedProperties as $propertyName => $propertyValue) {
                    if ($node->getProperty($propertyName) != $propertyValue) {
                        $node->setProperty($propertyName, $propertyValue);
                    }
                }
            }
        }

        $info = new Info();
        $info->setMessage('Translations were updated');

        $this->feedbackCollection->add($info);
    }
}
