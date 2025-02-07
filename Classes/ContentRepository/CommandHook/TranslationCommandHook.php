<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
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
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
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
        return $command;
    }

    public function onAfterHandle(CommandInterface $command): void
    {
        if ($command instanceof CreateNodeVariant) {
            $this->handleCreateNodeVariant($command);
        }
    }

    public function handleCreateNodeVariant(CreateNodeVariant $command): void
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

        if (!empty($propertiesToTranslate)) {
            $this->logger->error("translated", $propertiesToTranslate);
            $translatedProperties = $this->translationService->translate(
                $propertiesToTranslate,
                str_replace(['en_UK','en_US'], 'en', $command->targetOrigin->getCoordinate($this->languageDimensionId)),
                str_replace(['en_UK','en_US'], 'en', $command->sourceOrigin->getCoordinate($this->languageDimensionId))
            );
            $this->logger->error("translated", $translatedProperties);
        } else {
            return;
        }

        if (!empty($translatedProperties)) {
            $contentRepository = $this->contentRepositoryRegistry->get($this->contentRepositoryId);
            $newCommand = SetNodeProperties::create(
                $command->workspaceName,
                $command->nodeAggregateId,
                $command->targetOrigin,
                PropertyValuesToWrite::fromArray($translatedProperties)
            );
            $contentRepository->handle($newCommand);
        }
    }
}
