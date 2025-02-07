<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\NodeType\NodeType;

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
        if (array_key_exists($nodeType->name->value, $this->firstLevelCache)) {
            return $this->firstLevelCache[$nodeType->name->value];
        }
        $propertyDefinitions = $nodeType->getProperties();
        $translateProperties = [];
        foreach ($propertyDefinitions as $propertyName => $propertyDefinition) {
            if (array_key_exists('type', $propertyDefinition) && $propertyDefinition['type'] !== 'string') {
                continue;
            }
            if ($this->translateInlineEditables && ($propertyDefinitions[$propertyName]['ui']['inlineEditable'] ?? false)) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
                continue;
            }
            // @deprecated Fallback for renamed setting translateOnAdoption -> automaticTranslation
            if ($propertyDefinition['options']['automaticTranslation'] ?? ($propertyDefinition['options']['translateOnAdoption'] ?? false)) {
                $translateProperties[] = new TranslatablePropertyName($propertyName);
                continue;
            }
        }
        $this->firstLevelCache[$nodeType->name->value] = new TranslatablePropertyNames(...$translateProperties);
        return $this->firstLevelCache[$nodeType->name->value];
    }
}
