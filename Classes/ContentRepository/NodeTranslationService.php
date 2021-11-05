<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTranslationService
{

    /**
     * @Flow\Inject
     * @var TranslationServiceInterface
     */
    protected $translationService;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.enabled")
     * @var bool
     */
    protected $enabled;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     * @var bool
     */
    protected $translateRichtextProperties;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensionConfiguration;

    /**
     * @param NodeInterface $node
     * @param Context $context
     * @param $recursive
     * @return void
     */
    public function afterAdoptNode(NodeInterface $node, Context $context, $recursive)
    {
        if (!$this->enabled) {
            return;
        }

        $propertyDefinitions = $node->getNodeType()->getProperties();
        $adoptedNode = $context->getNodeByIdentifier((string)$node->getNodeAggregateIdentifier());

        $sourceLanguage = explode('_', $node->getContext()->getTargetDimensions()[$this->languageDimensionName])[0];
        $targetLanguage = explode('_', $context->getTargetDimensions()[$this->languageDimensionName])[0];

        $sourceLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$sourceLanguage];
        $targetLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$targetLanguage];

        if (array_key_exists('options', $sourceLanguagePreset) && array_key_exists('deeplLanguage', $sourceLanguagePreset['options'])) {
            $sourceLanguage = $sourceLanguagePreset['options']['deeplLanguage'];
        }

        if (array_key_exists('options', $targetLanguagePreset) && array_key_exists('deeplLanguage', $targetLanguagePreset['options'])) {
            $targetLanguage = $targetLanguagePreset['options']['deeplLanguage'];
        }

        if (empty($sourceLanguage) || empty($targetLanguage) || ($sourceLanguage == $targetLanguage)) {
            return;
        }

        $propertiesToTranslate = [];
        foreach ($adoptedNode->getProperties() as $propertyName => $propertyValue) {

            if (empty($propertyValue)) {
                continue;
            }
            if (!array_key_exists($propertyName, $propertyDefinitions)) {
                continue;
            }
            if ($propertyDefinitions[$propertyName]['type'] != 'string' || !is_string($propertyValue)) {
                continue;
            }
            if ((trim(strip_tags($propertyValue))) == "") {
                continue;
            }

            $translateProperty = false;
            $isInlineEditable = $propertyDefinitions[$propertyName]['ui']['inlineEditable'] ?? false;
            $isTranslateEnabled = $propertyDefinitions[$propertyName]['options']['translateOnAdoption'] ?? false;
            if ($this->translateRichtextProperties && $isInlineEditable == true) {
                $translateProperty = true;
            }
            if ($isTranslateEnabled) {
                $translateProperty = true;
            }

            if ($translateProperty) {
                $propertiesToTranslate[$propertyName] = $propertyValue;
            }
        }

        if (count($propertiesToTranslate) > 0) {
            $translatedProperties = $this->translationService->translate($propertiesToTranslate, $targetLanguage, $sourceLanguage);
            foreach($translatedProperties as $propertyName => $translatedValue) {
                if ($adoptedNode->getProperty($propertyName) != $translatedValue) {
                    $adoptedNode->setProperty($propertyName, $translatedValue);
                }
            }
        }
    }
}
