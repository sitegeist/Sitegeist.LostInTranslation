<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\EventStore\Events;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirective;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

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
    ) {
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        if ($this->enabled === false) {
            return $command;
        }

        return $command;
    }

    public function onAfterHandle(CommandInterface $command, Events $events): Commands
    {
        if ($this->enabled === false) {
            return Commands::createEmpty();
        }

        if ($command instanceof CreateNodeVariant) {
            return $this->createNodeVariantCommandWasHandled($command, $events);
        } else {
            return Commands::createEmpty();
        }
    }

    public function createNodeVariantCommandWasHandled(CreateNodeVariant $command, Events $events): Commands
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
        $targetDeeplLanguage =  $targetLanguageDirective?->deeplTargetId;

        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return Commands::createEmpty();
        }

        $command->targetOrigin->getCoordinate($this->languageDimension->id);
        $sourceNode = $this->contentGraphReadModel
            ->getContentGraph($command->workspaceName)
            ->getSubgraph($command->sourceOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions())
            ->findNodeById($command->nodeAggregateId);
        if ($sourceNode === null) {
            return Commands::createEmpty();
        }

        $nodeType = $this->nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            return Commands::createEmpty();
        }

        $translationDirective = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        if ($translationDirective->enabled === false) {
            return Commands::createEmpty();
        }

        $propertiesToTranslate = [];
        foreach ($translationDirective->translatablePropertyNames as $translatablePropertyName) {
            if ($sourceNode->hasProperty($translatablePropertyName)) {
                $sourceValue = $sourceNode->getProperty($translatablePropertyName);
                if (!empty($sourceValue)) {
                    $propertiesToTranslate[$translatablePropertyName->value] = $sourceValue;
                }
            }
        }

        if (empty($propertiesToTranslate)) {
            return Commands::createEmpty();
        }

        $translatedProperties = $this->translationService->translate(
            $propertiesToTranslate,
            $targetDeeplLanguage,
            $sourceDeeplLanguage,
        );

        if (empty($translatedProperties)) {
            return Commands::createEmpty();
        }

        $newCommand = SetNodeProperties::create(
            $command->workspaceName,
            $command->nodeAggregateId,
            $command->targetOrigin,
            PropertyValuesToWrite::fromArray($translatedProperties)
        );

        return Commands::fromArray([$newCommand]);
    }
}
