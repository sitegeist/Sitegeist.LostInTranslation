<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\TetheredNodeTypeDefinitions;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

final class TranslationCommandHook implements CommandHookInterface
{
    public function __construct(
        private bool $enabled,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory,
        private readonly DimensionValueDirectiveFactory $dimensionValueDirectiveFactory,
        private readonly TranslationServiceInterface $translationService,
        private readonly ContentDimension $languageDimension,
        private readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
        private readonly bool $experimentalApplyHtmlEntityDecodeAfterTranslation,
    ) {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        if ($this->enabled === false) {
            return $command;
        }

        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        if ($this->enabled === false) {
            return Commands::createEmpty();
        }

        if ($command instanceof CreateNodeVariant) {
            $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
            return $this->createNodeVariantCommandWasHandled($command);
        } else {
            return Commands::createEmpty();
        }
    }

    public function createNodeVariantCommandWasHandled(CreateNodeVariant $command): Commands
    {
        $sourceLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $this->languageDimension,
            $command->sourceOrigin
        );
        $targetLanguageDirective = $this->dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $this->languageDimension,
            $command->targetOrigin
        );

        $sourceDeeplLanguage = $sourceLanguageDirective?->deeplSourceId;
        $targetDeeplLanguage = $targetLanguageDirective?->deeplTargetId;

        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return Commands::createEmpty();
        }

        $command->targetOrigin->getCoordinate($this->languageDimension->id);
        $sourceSubgraph = $this->contentGraphReadModel
            ->getContentGraph($command->workspaceName)
            ->getSubgraph($command->sourceOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions());
        $sourceNode = $sourceSubgraph->findNodeById($command->nodeAggregateId);
        if ($sourceNode === null) {
            return Commands::createEmpty();
        }

        $nodeType = $this->nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            return Commands::createEmpty();
        }

        $additionalCommands = [];
        $additionalCommands[] = $this->tryPrepareSetNodeProperties(
            command: $command,
            sourceNode: $sourceNode,
            nodeType: $nodeType,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
        );
        $additionalCommands = array_merge(
            $additionalCommands,
            $this->handleTetheredChildren(
                command: $command,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
                nodeAggregateId: $sourceNode->aggregateId,
                tetheredNodeTypeDefinitions: $nodeType->tetheredNodeTypeDefinitions,
                subgraph: $sourceSubgraph,
            )
        );
        $additionalCommands = array_filter($additionalCommands);

        return Commands::fromArray($additionalCommands);
    }

    /**
     * @return array<int,SetNodeProperties|null>
     */
    private function handleTetheredChildren(
        CreateNodeVariant $command,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        NodeAggregateId $nodeAggregateId,
        TetheredNodeTypeDefinitions $tetheredNodeTypeDefinitions,
        ContentSubgraphInterface $subgraph,
    ): array {
        $commands = [];
        foreach ($tetheredNodeTypeDefinitions as $tetheredNodeTypeDefinition) {
            $tetheredChildNode = $subgraph->findNodeByPath(
                path: $tetheredNodeTypeDefinition->name,
                startingNodeAggregateId: $nodeAggregateId
            );
            if ($tetheredChildNode) {
                $tetheredChildNodeType = $this->nodeTypeManager->getNodeType($tetheredChildNode->nodeTypeName);
                if ($tetheredChildNodeType) {
                    $commands[] = $this->tryPrepareSetNodeProperties(
                        command: $command,
                        sourceNode: $tetheredChildNode,
                        nodeType: $tetheredChildNodeType,
                        sourceDeeplLanguage: $sourceDeeplLanguage,
                        targetDeeplLanguage: $targetDeeplLanguage,
                    );
                    $commands = array_merge(
                        $commands,
                        $this->handleTetheredChildren(
                            command: $command,
                            sourceDeeplLanguage: $sourceDeeplLanguage,
                            targetDeeplLanguage: $targetDeeplLanguage,
                            nodeAggregateId: $tetheredChildNode->aggregateId,
                            tetheredNodeTypeDefinitions: $tetheredChildNodeType->tetheredNodeTypeDefinitions,
                            subgraph: $subgraph,
                        )
                    );
                }
            }
        }

        return $commands;
    }

    private function tryPrepareSetNodeProperties(
        CreateNodeVariant $command,
        Node $sourceNode,
        NodeType $nodeType,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $translationDirective = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        if ($translationDirective->enabled === false) {
            return null;
        }

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($translationDirective->translatablePropertyNames as $translatablePropertyName) {
            if ($sourceNode->hasProperty($translatablePropertyName->propertyName)) {
                $propertyName = $translatablePropertyName->propertyName->value;
                $sourceValue = $sourceNode->getProperty($translatablePropertyName->propertyName);
                if (empty($sourceValue) || (is_string($sourceValue) && trim($sourceValue) === '')) {
                    continue;
                }
                assert($propertyName !== '');
                if (is_object($sourceValue) && ($connector = $translatablePropertyName->translationConnector)) {
                    $propertiesToTranslate[$propertyName] = $connector->extractTranslations($sourceValue);
                } elseif (is_string($sourceValue)) {
                    $propertiesToTranslate[$propertyName] = $sourceValue;
                }
            }
        }

        if (empty($propertiesToTranslate)) {
            return null;
        }

        if (count($propertiesToTranslate) > 0) {
            $propertiesToTranslateDeflated = ArrayFlatteningUtility::deflate($propertiesToTranslate);
            /** @var array<non-empty-string, string> $translatedPropertiesDeflated */
            $translatedPropertiesDeflated = $this->translationService->translate(
                $propertiesToTranslateDeflated,
                $targetDeeplLanguage,
                $sourceDeeplLanguage,
            );
            if ($this->experimentalApplyHtmlEntityDecodeAfterTranslation) {
                $translatedPropertiesDeflated = array_map(
                    fn(string $value): string => html_entity_decode($value),
                    $translatedPropertiesDeflated
                );
            }
            $translatedProperties = ArrayFlatteningUtility::enflate($translatedPropertiesDeflated);
        } else {
            $translatedProperties = [];
        }

        if (empty($translatedProperties)) {
            return null;
        }

        $propertiesToSet = [];
        foreach ($translatedProperties as $propertyName => $translatedValue) {
            $targetValue = null;
            if (is_array($translatedValue)) {
                $translatablePropertyName = $translationDirective->translatablePropertyNames->findByName($propertyName);
                if (
                    $translatablePropertyName instanceof TranslatablePropertyName
                    && $connector = $translatablePropertyName->translationConnector
                ) {
                    $sourceValue = $sourceNode->getProperty($propertyName);
                    if (is_object($sourceValue)) {
                        $targetValue = $connector->applyTranslations($sourceValue, $translatedValue);
                    }
                }
            } else {
                // Apply the optional per-property post-processor (e.g. coerce a translated uriPathSegment back into a
                // valid slug) to the scalar translated value.
                $postProcessor = $translationDirective->translatablePropertyNames->findByName($propertyName)?->postProcessor;
                if (is_string($translatedValue) && $postProcessor !== null) {
                    $translatedValue = $postProcessor->process($translatedValue);
                }
                $targetValue = $translatedValue;
            }
            if ($targetValue !== null) {
                $propertiesToSet[$propertyName] = $targetValue;
            }
        }

        if (empty($propertiesToSet)) {
            return null;
        }

        return SetNodeProperties::create(
            workspaceName: $command->workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $command->targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }
}
