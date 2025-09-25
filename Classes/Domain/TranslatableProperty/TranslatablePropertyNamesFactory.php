<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeType;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

class TranslatablePropertyNamesFactory
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     */
    protected $translateInlineEditables;

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
            $type = $propertyDefinition['type'];

            // @deprecated Fallback for renamed setting translateOnAdoption -> automaticTranslation
            $automaticTranslationIsEnabled = $propertyDefinition[ 'options' ][ 'automaticTranslation' ]
                ?? ($propertyDefinition[ 'options' ][ 'translateOnAdoption' ] ?? false);
            $isInlineEditable = $propertyDefinition['ui']['inlineEditable']
                ?? false;
            $translationConnector = $propertyDefinition['options']['automaticTranslationConnector']
                ?? null;

            if ($type === "string" && $this->translateInlineEditables && $isInlineEditable) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
            } elseif ($type === "string" && $automaticTranslationIsEnabled) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
            } elseif ($translationConnector && $automaticTranslationIsEnabled) {
                $translateProperties[] = new TranslatablePropertyName($propertyName, $translationConnector);
            }
        }
        $this->firstLevelCache[$nodeType->getName()] = new TranslatablePropertyNames(...$translateProperties);
        return $this->firstLevelCache[$nodeType->getName()];
    }
}
