<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Exception\NodeException;
use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTranslationService
{
    protected const TRANSLATION_STRATEGY_ONCE = 'once';
    protected const TRANSLATION_STRATEGY_SYNC = 'sync';

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
     * @Flow\InjectConfiguration(path="nodeTranslation.strategy")
     * @var string
     */
    protected string $nodeTranslationStrategy;

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
     * @Flow\InjectConfiguration(path="nodeTranslation.translationMapping")
     * @var array
     */
    protected $translationMapping;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensionConfiguration;

    /**
     * @var Context[]
     */
    protected $contextFirstLevelCache = [];

    /**
     * @Flow\Inject
     * @var ContextFactory
     */
    protected $contextFactory;

    /**
     * @param NodeInterface $node
     * @param Context $context
     * @param $recursive
     * @return void
     */
    public function afterAdoptNode(NodeInterface $node, Context $context, $recursive)
    {
        if (!$this->enabled && $this->nodeTranslationStrategy !== self::TRANSLATION_STRATEGY_ONCE) {
            return;
        }

        $adoptedNode = $context->getNodeByIdentifier((string) $node->getNodeAggregateIdentifier());
        $this->translateNode($node, $adoptedNode, $context);
    }

    /**
     * @param  NodeInterface  $node
     * @param  Workspace  $workspace
     * @return void
     */
    public function afterNodePublish(NodeInterface $node, Workspace $workspace)
    {
        if (!$this->enabled && $this->nodeTranslationStrategy !== self::TRANSLATION_STRATEGY_SYNC) {
            return;
        }

        $nodeSourceLanguage = explode('_', $node->getContext()->getTargetDimensions()[$this->languageDimensionName])[0];
        if (!array_key_exists($nodeSourceLanguage, $this->translationMapping)) {
            return;
        }

        foreach($this->translationMapping[$nodeSourceLanguage] as $targetLanguage) {
            $context = $this->getContextForLanguageDimensionAndWorkspaceName($targetLanguage, $workspace->getName());
            $adoptedNode = $context->adoptNode($node);
            $this->translateNode($node, $adoptedNode, $context);
        }
    }

    /**
     * @param  NodeInterface  $node
     * @param  NodeInterface  $adoptedNode
     * @param  Context  $context
     * @return void
     */
    protected function translateNode(NodeInterface $node, NodeInterface $adoptedNode, Context $context): void
    {
        $propertyDefinitions = $node->getNodeType()->getProperties();

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

        // Sync internal properties
        $adoptedNode->setNodeType($node->getNodeType());
        $adoptedNode->setRemoved($node->isRemoved());
        $adoptedNode->setHidden($node->isHidden());
        $adoptedNode->setHiddenInIndex($node->isHiddenInIndex());
        $adoptedNode->setHiddenBeforeDateTime($node->getHiddenBeforeDateTime());
        $adoptedNode->setHiddenAfterDateTime($node->getHiddenAfterDateTime());

        $properties = (array)$node->getProperties();
        $propertiesToTranslate = [];
        foreach ($properties as $propertyName => $propertyValue) {

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
            // @deprecated Fallback for renamed setting translateOnAdoption -> translate
            $isTranslateEnabled = $propertyDefinitions[$propertyName]['options']['translate'] ?? ($propertyDefinitions[$propertyName]['options']['translateOnAdoption'] ?? false);
            if ($this->translateRichtextProperties && $isInlineEditable == true) {
                $translateProperty = true;
            }
            if ($isTranslateEnabled) {
                $translateProperty = true;
            }

            if ($translateProperty) {
                $propertiesToTranslate[$propertyName] = $propertyValue;
                unset($properties[$propertyName]);
            }
        }

        if (count($propertiesToTranslate) > 0) {
            $translatedProperties = $this->translationService->translate($propertiesToTranslate, $targetLanguage, $sourceLanguage);
            $translatedProperties = array_merge($translatedProperties, $properties);
            foreach ($translatedProperties as $propertyName => $translatedValue) {
                if ($adoptedNode->getProperty($propertyName) != $translatedValue) {
                    $adoptedNode->setProperty($propertyName, $translatedValue);
                }
            }
        }
    }

    /**
     * @param  string  $language
     * @param  string  $workspaceName
     * @return Context
     */
    protected function getContextForLanguageDimensionAndWorkspaceName(string $language, string $workspaceName = 'live')
    {
        $dimensionAndWorkspaceIdentifierHash = md5($language . $workspaceName);

        if (array_key_exists($dimensionAndWorkspaceIdentifierHash, $this->contextFirstLevelCache)) {
            return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash];
        }

        return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash] = $this->contextFactory->create(
            array(
                'workspaceName' => $workspaceName,
                'invisibleContentShown' => true,
                'dimensions' => array(
                    $this->languageDimensionName => array($language),
                ),
                'targetDimensions' => array(
                    $this->languageDimensionName => $language,
                ),
            )
        );
    }
}
