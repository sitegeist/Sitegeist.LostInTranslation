<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchroniser;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SynchronisationRule;
use Sitegeist\LostInTranslation\Domain\SynchronisationRules;
use Sitegeist\LostInTranslation\Domain\SynchronizationStrategy;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Domain\TranslationStrategy;

/**
 * Command hook that fires the auto-sync rules from
 * `Sitegeist.LostInTranslation.nodeTranslation.synchronization` after a workspace publish.
 *
 * For every rule whose `sourceWorkspaceName` matches the publish target, the hook iterates the
 * stale-translation records sitting at the rule's `(targetWorkspaceName, targetLanguage)` and
 * emits one command per record:
 *  - **target variant missing** → `CreateNodeVariant`; the existing
 *    {@see TranslationCommandHook} cascades the translated `SetNodeProperties` automatically.
 *  - **target variant exists** → a translated `SetNodeProperties` built by
 *    {@see StalePropertyCommandBuilder}.
 *
 * The hook only reads the {@see StaleTranslationFinder} (the projection has already caught up
 * by the time `onAfterHandle` runs, per the CR contract) and returns commands; it does not
 * dispatch directly. AI authorship is flagged via {@see AISystemTranslationRuntimeState} so the
 * returned commands' events are attributed to the AI service rather than the publishing editor.
 */
final class PublicationSynchronisationCommandHook implements CommandHookInterface
{
    private ?StaleTranslationFinder $resolvedStaleTranslationFinder = null;

    public function __construct(
        private readonly bool $enabled,
        private readonly SynchronisationRules $rules,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly StalePropertyCommandBuilder $stalePropertyCommandBuilder,
        private readonly FullWorkspaceSynchroniser $fullWorkspaceSynchroniser,
        private readonly DimensionValueDirectiveFactory $dimensionValueDirectiveFactory,
        private readonly TranslationServiceInterface $translationService,
        private readonly ContentDimension $languageDimension,
        private readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    private function staleTranslationFinder(): StaleTranslationFinder
    {
        if ($this->resolvedStaleTranslationFinder === null) {
            $this->resolvedStaleTranslationFinder = $this->contentRepositoryRegistry
                ->get($this->contentRepositoryId)
                ->projectionState(StaleTranslationReadModel::class)
                ->staleTranslationFinder;
        }
        return $this->resolvedStaleTranslationFinder;
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        if (!$this->enabled || $this->rules->isEmpty()) {
            return Commands::createEmpty();
        }
        if (!($command instanceof PublishWorkspace) && !($command instanceof PublishIndividualNodesFromWorkspace)) {
            return Commands::createEmpty();
        }

        // The command itself does not carry the publish target — read it from the resulting event.
        $publicationTarget = $this->findPublicationTarget($events);
        if ($publicationTarget === null) {
            return Commands::createEmpty();
        }

        $matchingRules = $this->rules->forPublicationTarget($publicationTarget);
        if ($matchingRules->isEmpty()) {
            return Commands::createEmpty();
        }

        $additionalCommands = [];
        foreach ($matchingRules as $rule) {
            foreach ($this->commandsForRule($rule) as $cmd) {
                $additionalCommands[] = $cmd;
            }
        }

        if ($additionalCommands === []) {
            return Commands::createEmpty();
        }

        // Mark the cascade as AI-authored. The existing TranslationCommandHook resets the state
        // at the start of every `onAfterHandle`, so the attribution is scoped to the dispatched
        // commands themselves and does not leak to the next user-initiated command.
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());

        return Commands::fromArray($additionalCommands);
    }

    private function findPublicationTarget(PublishedEvents $events): ?WorkspaceName
    {
        foreach ($events as $event) {
            if ($event instanceof WorkspaceWasPublished) {
                return $event->targetWorkspaceName;
            }
        }
        return null;
    }

    /**
     * @return list<CommandInterface>
     */
    private function commandsForRule(SynchronisationRule $rule): array
    {
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceLanguage]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetLanguage]);
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDsp);
        $targetWorkspace = WorkspaceName::fromString($rule->targetWorkspaceName);

        $sourceDeepl = $this->dimensionValueDirectiveFactory
            ->tryCreateForDimensionAndOriginDimensionSpacePoint(
                $this->languageDimension,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDsp),
            )?->deeplSourceId;
        $targetDeepl = $this->dimensionValueDirectiveFactory
            ->tryCreateForDimensionAndOriginDimensionSpacePoint(
                $this->languageDimension,
                $targetOrigin,
            )?->deeplTargetId;
        if ($sourceDeepl === null || $targetDeepl === null) {
            return [];
        }

        // Full strategy: walk the whole target subtree (delegated to FullWorkspaceSynchroniser,
        // which also encodes the "stale always forces a refresh" override). `keep-existing` maps to
        // skipExisting=true, `force-refresh` to false.
        if ($rule->synchronizationStrategy === SynchronizationStrategy::Full) {
            return iterator_to_array($this->fullWorkspaceSynchroniser->buildSynchronisationCommands(
                contentRepositoryId: $this->contentRepositoryId,
                targetWorkspaceName: $targetWorkspace,
                sourceDimensionSpacePoint: $sourceDsp,
                targetDimensionSpacePoint: $targetDsp,
                sourceDeeplLanguage: $sourceDeepl,
                targetDeeplLanguage: $targetDeepl,
                skipExisting: $rule->translationStrategy === TranslationStrategy::KeepExisting,
                useCache: true,
            ));
        }

        $sourceSubgraph = $this->contentGraphReadModel
            ->getContentGraph($targetWorkspace)
            ->getSubgraph($sourceDsp, VisibilityConstraints::withoutRestrictions());
        $targetSubgraph = $this->contentGraphReadModel
            ->getContentGraph($targetWorkspace)
            ->getSubgraph($targetDsp, VisibilityConstraints::withoutRestrictions());

        $commands = [];
        foreach ($this->staleTranslationFinder()->findAll() as $stale) {
            assert($stale instanceof StaleTranslation);
            if (!$stale->workspaceName->equals($targetWorkspace)) {
                continue;
            }
            if ($stale->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            $sourceNode = $sourceSubgraph->findNodeById($stale->nodeAggregateId);
            // Source variant not present in the target workspace at the source dimension —
            // nothing to translate from. Leave the stale row alone.
            if ($sourceNode === null) {
                continue;
            }
            $targetExists = $targetSubgraph->findNodeById($stale->nodeAggregateId) !== null;
            if ($targetExists) {
                $command = $this->stalePropertyCommandBuilder->buildSetNodeProperties(
                    nodeTypeManager: $this->nodeTypeManager,
                    sourceNode: $sourceNode,
                    stalePropertyNames: $stale->propertyNames,
                    targetOrigin: $stale->originDimensionSpacePoint,
                    sourceDeeplLanguage: $sourceDeepl,
                    targetDeeplLanguage: $targetDeepl,
                );
                if ($command !== null) {
                    $commands[] = $command;
                }
                continue;
            }
            // Tethered children are created together with their non-tethered ancestor's variant —
            // the existing TranslationCommandHook handles the cascade, so we don't emit a
            // CreateNodeVariant for them ourselves.
            if ($sourceNode->classification->isTethered()) {
                continue;
            }
            $commands[] = CreateNodeVariant::create(
                $targetWorkspace,
                $stale->nodeAggregateId,
                $sourceNode->originDimensionSpacePoint,
                $targetOrigin,
            );
        }
        return $commands;
    }
}
