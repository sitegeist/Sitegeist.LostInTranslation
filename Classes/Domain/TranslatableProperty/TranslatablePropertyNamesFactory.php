<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

class TranslatablePropertyNamesFactory
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     */
    protected $translateInlineEditables;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="nodeTranslation.defaultInlineEditableTranslationMode")
     */
    protected $defaultInlineEditableTranslationMode;

    /**
     * @var string
     * @Flow\InjectConfiguration(path="nodeTranslation.defaultNonInlineEditableTranslationMode")
     */
    protected $defaultNonInlineEditableTranslationMode;

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

            if ($automaticTranslationIsEnabled === false) {
                continue;
            }

            if ($type === "string" && $this->translateInlineEditables && $isInlineEditable) {
                $translateProperties[] = new TranslatablePropertyName(
                    $propertyName,
                    null,
                    $this->getStringTranslationMode($propertyDefinition, $isInlineEditable)
                );
            } elseif ($type === "string" && $automaticTranslationIsEnabled === true) {
                $translateProperties[] = new TranslatablePropertyName(
                    $propertyName,
                    null,
                    $this->getStringTranslationMode($propertyDefinition, $isInlineEditable)
                );
            } elseif ($translationConnector && ($this->translateTypesWithConnectors || $automaticTranslationIsEnabled)) {
                $translationConnectorInstance = $this->objectManager->get($translationConnector);
                assert($translationConnectorInstance instanceof TranslationConnectorInterface);
                $translateProperties[] = new TranslatablePropertyName($propertyName, $translationConnectorInstance);
            }
        }
        $this->firstLevelCache[$nodeType->getName()] = new TranslatablePropertyNames(...$translateProperties);
        return $this->firstLevelCache[$nodeType->getName()];
    }

    /**
     * @param array<string, mixed> $propertyDefinition
     */
    private function getStringTranslationMode(array $propertyDefinition, bool $isInlineEditable): StringTranslationMode
    {
        $configuredMode = $propertyDefinition['options']['stringTranslationMode']
            ?? (
                $isInlineEditable
                    ? $this->defaultInlineEditableTranslationMode
                    : $this->defaultNonInlineEditableTranslationMode
            );

        return StringTranslationMode::from($configuredMode);
    }
}
