<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactory;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Service\PublishingService;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @Flow\Scope("singleton")
 */
class NodeTranslationService
{
    public const TRANSLATION_STRATEGY_ONCE = 'once';
    public const TRANSLATION_STRATEGY_SYNC = 'sync';
    public const TRANSLATION_STRATEGY_NONE = 'none';

    /**
     * @Flow\Inject
     * @var TranslationServiceInterface
     */
    protected $translationService;

    /**
     * @Flow\Inject
     * @var PublishingService
     */
    protected $publishingService;

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
     * @Flow\InjectConfiguration(path="nodeTranslation.skipAuthorizationChecks")
     * @var bool
     */
    protected $skipAuthorizationChecks;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array<string,array{'default': string, 'defaultPreset': string, 'presets': array<string,mixed> }>
     */
    protected $contentDimensionConfiguration;

    /**
     * @var Context[]
     */
    protected $contextFirstLevelCache = [];

    /**
     * @Flow\Inject
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @Flow\Inject
     * @var \Neos\Flow\Security\Context
     */
    protected $securityContext;

    /**
     * @Flow\Inject
     * @var NodeUriPathSegmentGenerator
     */
    protected $nodeUriPathSegmentGenerator;

    /**
     * @Flow\Inject
     * @var TranslatablePropertyNamesFactory
     */
    protected $translatablePropertiesFactory;

    /**
     * This is an internal property and should always be 'live'.
     * Its only purpose is to be overridden in functional testing.
     *
     * @var string
     */
    protected $liveWorkspaceName = 'live';

    /**
     * @param NodeInterface $node
     * @param Context $context
     * @param bool $recursive
     * @return void
     */
    public function afterAdoptNode(NodeInterface $node, Context $context, bool $recursive): void
    {
        if (!$this->enabled) {
            return;
        }

        $isAutomaticTranslationEnabledForNodeType = $node->getNodeType()->getConfiguration('options.automaticTranslation') ?? true;
        if (!$isAutomaticTranslationEnabledForNodeType) {
            return;
        }

        $targetDimensionValue = $context->getTargetDimensions()[$this->languageDimensionName];
        $languagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$targetDimensionValue];
        $translationStrategy = $languagePreset['options']['translationStrategy'] ?? self::TRANSLATION_STRATEGY_ONCE;
        if (!in_array($translationStrategy, [self::TRANSLATION_STRATEGY_ONCE, self::TRANSLATION_STRATEGY_SYNC])) {
            return;
        }

        $adoptedNode = $context->getNodeByIdentifier((string)$node->getIdentifier());
        if ($adoptedNode instanceof NodeInterface) {
            $this->translateNode($node, $adoptedNode, $context);
        }
    }

    /**
     * @param NodeInterface $node
     * @param Workspace $workspace
     * @return void
     */
    public function afterNodePublish(NodeInterface $node, Workspace $workspace): void
    {
        if (!$this->enabled) {
            return;
        }

        if ($workspace->getName() !== $this->liveWorkspaceName) {
            return;
        }

        if ($this->skipAuthorizationChecks) {
            $this->securityContext->withoutAuthorizationChecks(function () use ($node) {
                $this->syncNode($node, $this->liveWorkspaceName);
            });
        } else {
            $this->syncNode($node, $this->liveWorkspaceName);
        }
    }

    /**
     * All translatable properties from the source node are collected and passed translated via deepl and
     * applied to the target node
     *
     * @param NodeInterface $sourceNode
     * @param NodeInterface $targetNode
     * @param Context $context
     * @return void
     */
    public function translateNode(NodeInterface $sourceNode, NodeInterface $targetNode, Context $context): void
    {
        $translatableProperties = $this->translatablePropertiesFactory->createForNodeType($sourceNode->getNodeType());

        $sourceDimensionValue = $sourceNode->getContext()->getTargetDimensions()[$this->languageDimensionName];
        $targetDimensionValue = $context->getTargetDimensions()[$this->languageDimensionName];

        $sourceLanguage = explode('_', $sourceDimensionValue)[0];
        $targetLanguage = explode('_', $targetDimensionValue)[0];

        $sourceLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$sourceDimensionValue];
        $targetLanguagePreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['presets'][$targetDimensionValue];

        if (array_key_exists('options', $sourceLanguagePreset) && array_key_exists('deeplLanguage', $sourceLanguagePreset['options'])) {
            $sourceLanguage = $sourceLanguagePreset['options']['deeplLanguage'];
        }

        if (array_key_exists('options', $targetLanguagePreset) && array_key_exists('deeplLanguage', $targetLanguagePreset['options'])) {
            $targetLanguage = $targetLanguagePreset['options']['deeplLanguage'];
        }
        if (empty($sourceLanguage) || empty($targetLanguage) || ($sourceLanguage == $targetLanguage)) {
            return;
        }

        $properties = (array)$sourceNode->getProperties();
        $propertiesToTranslate = [];
        foreach ($properties as $propertyName => $propertyValue) {
            if (empty($propertyValue) || !is_string($propertyValue)) {
                continue;
            }
            if (!$translatableProperties->isTranslatable($propertyName)) {
                continue;
            }
            if ((trim(strip_tags($propertyValue))) == "") {
                continue;
            }
            $propertiesToTranslate[$propertyName] = $propertyValue;
            unset($properties[$propertyName]);
        }

        if (count($propertiesToTranslate) > 0) {
            $translatedProperties = $this->translationService->translate($propertiesToTranslate, $targetLanguage, $sourceLanguage);
            $properties = array_merge($translatedProperties, $properties);
        }

        foreach ($properties as $propertyName => $propertyValue) {
            if ($targetNode->getProperty($propertyName) != $propertyValue) {
                $targetNode->setProperty($propertyName, $propertyValue);
            }

            // Make sure the uriPathSegment is valid
            if ($targetNode->getProperty('uriPathSegment') && !preg_match('/^[a-z0-9\-]+$/i', $targetNode->getProperty('uriPathSegment'))) {
                $targetNode->setProperty('uriPathSegment', $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $targetNode->getProperty('uriPathSegment')));
            }
        }
    }

    /**
     * @param string $language
     * @param string $workspaceName
     * @return Context
     */
    public function getContextForLanguageDimensionAndWorkspaceName(string $language, string $workspaceName = 'live'): Context
    {
        $dimensionAndWorkspaceIdentifierHash = md5(trim($language . $workspaceName));

        if (array_key_exists($dimensionAndWorkspaceIdentifierHash, $this->contextFirstLevelCache)) {
            return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash];
        }

        return $this->contextFirstLevelCache[$dimensionAndWorkspaceIdentifierHash] = $this->contextFactory->create(
            array(
                'workspaceName' => $workspaceName,
                'invisibleContentShown' => true,
                'removedContentShown' => true,
                'inaccessibleContentShown' => true,
                'dimensions' => array(
                    $this->languageDimensionName => array($language),
                ),
                'targetDimensions' => array(
                    $this->languageDimensionName => $language,
                ),
            )
        );
    }

    /**
     * Checks the requirements if a node can be synchronised and executes the sync.
     *
     * @param NodeInterface $sourceNode
     * @param string $workspaceName
     * @return void
     */
    public function syncNode(NodeInterface $sourceNode, string $workspaceName = 'live'): void
    {
        $isAutomaticTranslationEnabledForNodeType = $sourceNode->getNodeType()->getConfiguration('options.automaticTranslation') ?? true;
        if (!$isAutomaticTranslationEnabledForNodeType) {
            return;
        }

        $nodeSourceDimensionValue = $sourceNode->getContext()->getTargetDimensions()[$this->languageDimensionName];
        $defaultPreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset'];

        if ($nodeSourceDimensionValue !== $defaultPreset) {
            return;
        }
        foreach ($this->contentDimensionConfiguration[$this->languageDimensionName]['presets'] as $presetIdentifier => $languagePreset) {
            if ($nodeSourceDimensionValue === $presetIdentifier) {
                continue;
            }

            $translationStrategy = $languagePreset['options']['translationStrategy'] ?? null;
            if ($translationStrategy !== self::TRANSLATION_STRATEGY_SYNC) {
                continue;
            }
            if (!$sourceNode->isRemoved()) {
                $context = $this->getContextForLanguageDimensionAndWorkspaceName($presetIdentifier, $workspaceName);
                $context->getFirstLevelNodeCache()->flush();

                $targetNode = $context->adoptNode($sourceNode);

                // Move node if targetNode has no parent or node parents are not matching
                if (!$targetNode->getParent() || ($sourceNode->getParentPath() !== $targetNode->getParentPath())) {
                    $referenceNode = $context->getNodeByIdentifier($sourceNode->getParent()->getIdentifier());
                    if ($referenceNode instanceof NodeInterface) {
                        $targetNode->moveInto($referenceNode);
                    }
                }

                // Sync internal properties
                $targetNode->setNodeType($sourceNode->getNodeType());
                $targetNode->setHidden($sourceNode->isHidden());
                $targetNode->setHiddenInIndex($sourceNode->isHiddenInIndex());
                $targetNode->setHiddenBeforeDateTime($sourceNode->getHiddenBeforeDateTime());
                $targetNode->setHiddenAfterDateTime($sourceNode->getHiddenAfterDateTime());
                $targetNode->setIndex($sourceNode->getIndex());

                $this->translateNode($sourceNode, $targetNode, $context);

                $context->getFirstLevelNodeCache()->flush();
                $this->publishingService->publishNode($targetNode);
            } else {
                $removeContext = $this->getContextForLanguageDimensionAndWorkspaceName($presetIdentifier, $workspaceName);
                $targetNode = $removeContext->getNodeByIdentifier($sourceNode->getIdentifier());
                if ($targetNode !== null) {
                    $targetNode->setRemoved(true);
                }
            }
        }
    }

    public function resetContextCache(): void
    {
        $this->contextFirstLevelCache = [];
    }
}
