<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\EventStore\Events;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphProjectionInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @implements  CatchUpHookInterface<ContentGraphProjectionInterface>
 */
final class TranslationCommandHook implements CommandHookInterface
{
    private LoggerInterface $logger;

    public function __construct(
        private bool $enabled,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly TranslatablePropertyNamesFactory $translatablePropertyNamesFactory,
        private readonly TranslationServiceInterface $translationService,
        private readonly ContentDimensionId $languageDimensionId,
    ) {
    }

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
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
        $source = $this->contentGraphReadModel
            ->getContentGraph($command->workspaceName)
            ->getSubgraph($command->sourceOrigin->toDimensionSpacePoint(), VisibilityConstraints::withoutRestrictions())
            ->findNodeById($command->nodeAggregateId);

        $this->logger->error("handleCreateNodeVariant", [$command->nodeAggregateId, $command->sourceOrigin->jsonSerialize(), $command->targetOrigin->jsonSerialize()]);

        $nodeType = $this->nodeTypeManager->getNodeType($source->nodeTypeName);
        $translatableProperties = $this->translatablePropertyNamesFactory->createForNodeType($nodeType);

        $propertiesToTranslate = [];
        foreach ($translatableProperties as $translatableProperty) {
            if ($source->hasProperty($translatableProperty)) {
                $sourceValue = $source->getProperty($translatableProperty);
                if (!empty($sourceValue)) {
                    $propertiesToTranslate[$translatableProperty->value] = $sourceValue;
                }
            }
        }

        if (empty($propertiesToTranslate)) {
            return Commands::createEmpty();
        }

        $translatedProperties = $this->translationService->translate(
            $propertiesToTranslate,
            str_replace(['en_UK','en_US'], 'en', $command->targetOrigin->getCoordinate($this->languageDimensionId)),
            str_replace(['en_UK','en_US'], 'en', $command->sourceOrigin->getCoordinate($this->languageDimensionId))
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
