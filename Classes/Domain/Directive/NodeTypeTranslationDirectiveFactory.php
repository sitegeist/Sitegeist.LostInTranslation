<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Sitegeist\LostInTranslation\Domain\PostProcessor\TranslatedPropertyPostProcessorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

class NodeTypeTranslationDirectiveFactory
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
     * @var array<string, NodeTypeTranslationDirective>
     */
    protected $firstLevelCache = [];

    public function createForNodeType(NodeType $nodeType): NodeTypeTranslationDirective
    {
        if (array_key_exists($nodeType->name->value, $this->firstLevelCache)) {
            return $this->firstLevelCache[$nodeType->name->value];
        }
        $propertyDefinitions = $nodeType->getConfiguration('options.automaticTranslation')
            ? $nodeType->getProperties()
            : [];
        $translateProperties = [];
        foreach ($propertyDefinitions as $propertyName => $propertyDefinition) {
            $type = $propertyDefinition['type'] ?? null;
            if (empty($type)) {
                continue;
            }

            $automaticTranslationIsEnabled = $propertyDefinition[ 'options' ][ 'automaticTranslation' ] ?? null;
            $isInlineEditable = $propertyDefinition['ui']['inlineEditable'] ?? false;
            $translationConnector = $this->translationConnectors[$type] ?? null;

            if ($automaticTranslationIsEnabled === false) {
                continue;
            }

            // Optional per-property post-processor (e.g. coerce a translated uriPathSegment back into a valid slug),
            // configured via `properties.<name>.options.translationPostProcessor`. Applied to the translated scalar
            // value by the translation pipeline.
            $postProcessor = $this->resolvePostProcessor($propertyDefinition['options']['translationPostProcessor'] ?? null);

            if ($type === "string" && $this->translateInlineEditables && $isInlineEditable) {
                $translateProperties[] = new TranslatablePropertyName(PropertyName::fromString($propertyName), null, $postProcessor);
            } elseif ($type === "string" && $automaticTranslationIsEnabled === true) {
                $translateProperties[] = new TranslatablePropertyName(PropertyName::fromString($propertyName), null, $postProcessor);
            } elseif ($translationConnector && ($this->translateTypesWithConnectors || $automaticTranslationIsEnabled)) {
                $translationConnectorInstance = $this->objectManager->get($translationConnector);
                assert($translationConnectorInstance instanceof TranslationConnectorInterface);
                $translateProperties[] = new TranslatablePropertyName(PropertyName::fromString($propertyName), $translationConnectorInstance, $postProcessor);
            }
        }

        $explicitConfiguration = $nodeType->getConfiguration('options.automaticTranslation');
        $this->firstLevelCache[$nodeType->name->value] = new NodeTypeTranslationDirective(
            (is_bool($explicitConfiguration)) ? $explicitConfiguration : count($translateProperties) > 0,
            TranslatablePropertyNames::fromArray($translateProperties),
        );

        return $this->firstLevelCache[$nodeType->name->value];
    }

    private function resolvePostProcessor(mixed $postProcessorClassName): ?TranslatedPropertyPostProcessorInterface
    {
        if (!is_string($postProcessorClassName) || $postProcessorClassName === '') {
            return null;
        }
        $postProcessor = $this->objectManager->get($postProcessorClassName);
        assert($postProcessor instanceof TranslatedPropertyPostProcessorInterface);
        return $postProcessor;
    }
}
