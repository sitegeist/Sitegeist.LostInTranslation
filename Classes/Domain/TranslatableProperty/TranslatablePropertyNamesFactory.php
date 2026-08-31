<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationArrayConnectorInterface;

class TranslatablePropertyNamesFactory
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     */
    protected $translateInlineEditables;

    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateTypesWithConnectors")
     */
    protected $translateTypesWithConnectors;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.translationConnectors")
     * @var array<class-string, class-string>
     */
    protected $translationConnectors;

    /**
     * @Flow\Inject
     * @var ObjectManagerInterface
     */
    protected $objectManager;

    /**
     * @var array<string, TranslatablePropertyNames>
     */
    protected $firstLevelCache = [];

    public function createForNodeType(NodeType $nodeType): TranslatablePropertyNames
    {
        if (array_key_exists($nodeType->getName(), $this->firstLevelCache)) {
            return $this->firstLevelCache[$nodeType->getName()];
        }
        $propertyDefinitions = $nodeType->getProperties();
        $translateProperties = [];
        foreach ($propertyDefinitions as $propertyName => $propertyDefinition) {
            $type = $propertyDefinition['type'] ?? null;
            if (empty($type)) {
                continue;
            }

            // @deprecated Fallback for renamed setting translateOnAdoption -> automaticTranslation
            $automaticTranslationIsEnabled = $propertyDefinition[ 'options' ][ 'automaticTranslation' ]
                ?? ($propertyDefinition[ 'options' ][ 'translateOnAdoption' ] ?? null);
            $isInlineEditable = $propertyDefinition['ui']['inlineEditable']
                ?? false;
            $translationConnector = $this->translationConnectors[$type]
                ?? null;
            $translationConnectorFromPropertyDefinition = $propertyDefinition['options']['translationConnector']
                ?? null;

            if ($automaticTranslationIsEnabled === false) {
                continue;
            }

            if ($type === "string" && $this->translateInlineEditables && $isInlineEditable) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
            } elseif ($type === "string" && $automaticTranslationIsEnabled === true) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
            } elseif ($translationConnector && ($this->translateTypesWithConnectors || $automaticTranslationIsEnabled)) {
                $translationConnectorInstance = $this->objectManager->get($translationConnector);
                assert($translationConnectorInstance instanceof TranslationConnectorInterface);
                $translateProperties[] = new TranslatablePropertyName($propertyName, $translationConnectorInstance);
            } elseif ($translationConnectorFromPropertyDefinition && $automaticTranslationIsEnabled) {
                $translationConnectorInstance = $this->objectManager->get($translationConnectorFromPropertyDefinition);
                if ($type === "array") {
                    assert($translationConnectorInstance instanceof TranslationArrayConnectorInterface);
                } else {
                    assert($translationConnectorInstance instanceof TranslationConnectorInterface);
                }
                $translateProperties[] = new TranslatablePropertyName($propertyName, $translationConnectorInstance);
            }
        }
        $this->firstLevelCache[$nodeType->getName()] = new TranslatablePropertyNames(...$translateProperties);
        return $this->firstLevelCache[$nodeType->getName()];
    }
}
