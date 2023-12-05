<?php
declare(strict_types=1);
namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Domain\Model\NodeType;

class TranslatablePropertiesFactory
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     */
    protected $translateInlineEditables;

    protected $firstLevelCache = [];

    public function createForNodeType(NodeType $nodeType): TranslatableProperties
    {
        if (array_key_exists($nodeType->getName(), $this->firstLevelCache)) {
            return $this->firstLevelCache[$nodeType->getName()];
        }
        $propertyDefinitions = $nodeType->getProperties();
        $translateProperties = [];
        foreach ($propertyDefinitions as $propertyName => $propertyDefinition) {
            if ($propertyDefinition['type'] !== 'string') {
                continue;
            }
            if ($this->translateInlineEditables && ($propertyDefinitions[$propertyName]['ui']['inlineEditable'] ?? false)) {
                $translateProperties[] = new TranslatableProperty($propertyName);
                continue;
            }
            // @deprecated Fallback for renamed setting translateOnAdoption -> automaticTranslation
            if ($propertyDefinition['options']['automaticTranslation'] ?? ($propertyDefinition['options']['translateOnAdoption'] ?? false)) {
                $translateProperties[] = new TranslatableProperty($propertyName);
                continue;
            }
        }
        $this->firstLevelCache[$nodeType->getName()] = new TranslatableProperties(...$translateProperties);
        return $this->firstLevelCache[$nodeType->getName()];
    }

}
