<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\Flow\Annotations as Flow;

class NodeTypeTranslationDirectiveFactory
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="nodeTranslation.translateInlineEditables")
     */
    protected $translateInlineEditables;

    /**
     * @var array<string, NodeTypeTranslationDirective>
     */
    protected $firstLevelCache = [];

    public function createForNodeType(NodeType $nodeType): NodeTypeTranslationDirective
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
            $explicitSetting = $propertyDefinition['options']['automaticTranslation'] ?? null;
            if (is_bool($explicitSetting)) {
                if ($explicitSetting === true) {
                    $translateProperties[] = PropertyName::fromString($propertyName);
                }
                continue;
            }
            $isInlineEditable = $propertyDefinition['ui']['inlineEditable'] ?? false;
            if ($this->translateInlineEditables && $isInlineEditable) {
                $translateProperties[] = PropertyName::fromString($propertyName);
                continue;
            }
        }

        $this->firstLevelCache[$nodeType->name->value] = new NodeTypeTranslationDirective(
            $nodeType->getConfiguration('options.automaticTranslation'),
            PropertyNames::fromArray($translateProperties),
        );

        return $this->firstLevelCache[$nodeType->name->value];
    }
}
