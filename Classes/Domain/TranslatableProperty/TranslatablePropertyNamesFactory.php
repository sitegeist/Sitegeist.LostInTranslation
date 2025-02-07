<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
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
     * @var array<string, PropertyNames>
     */
    protected $firstLevelCache = [];

    public function createForNodeType(NodeType $nodeType): PropertyNames
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
                $translateProperties[] = PropertyName::fromString($propertyName);
                continue;
            }
            if ($propertyDefinition['options']['automaticTranslation'] ?? false) {
                $translateProperties[] = PropertyName::fromString($propertyName);
                continue;
            }
        }
        $this->firstLevelCache[$nodeType->name->value] = PropertyNames::fromArray($translateProperties);
        return $this->firstLevelCache[$nodeType->name->value];
    }
}
