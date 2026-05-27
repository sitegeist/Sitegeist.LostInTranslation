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
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindAncestorNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindClosestNodeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationRules;
use Sitegeist\LostInTranslation\Domain\SynchronizationScope;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * Command hook that fires the auto-sync rules from
 * `Sitegeist.LostInTranslation.nodeTranslation.synchronization` after a workspace publish.
 *
 * Automatic synchronization is always stale-driven: for every rule whose `sourceWorkspaceName`
 * matches the publish target, the hook iterates the stale-translation records sitting at the rule's
 * `(targetWorkspaceName, targetDimension)` and emits one command per record:
 *  - **target variant missing** → `CreateNodeVariant`; the existing
 *    {@see TranslationCommandHook} cascades the translated `SetNodeProperties` automatically.
 *  - **target variant exists** → a translated `SetNodeProperties` built by
 *    {@see StalePropertyCommandBuilder}.
 *
 * The rule's {@see SynchronizationScope} gates which records are acted on:
 *  - {@see SynchronizationScope::Document} mirrors the whole structure (documents AND content).
 *  - {@see SynchronizationScope::Content} only acts on records whose containing Document already
 *    exists in the target dimension, and never creates Document variants automatically.
 *
 * Emitted commands are ordered ancestor-before-descendant (by source-tree depth) so a parent
 * `CreateNodeVariant` — which materialises tethered descendants such as a document's content
 * collection — is dispatched before a deeper node's own `CreateNodeVariant`.
 *
 * The hook only reads the {@see StaleTranslationFinder} (the projection has already caught up
 * by the time `onAfterHandle` runs, per the CR contract) and returns commands; it does not
 * dispatch directly. AI authorship is flagged via {@see AISystemTranslationRuntimeState} so the
 * returned commands' events are attributed to the AI service rather than the publishing editor.
 *
 * Walking the whole tree from the root (regardless of stale state) is deliberately NOT done here —
 * that is the separate, manual `synchronize --full` CLI command
 * ({@see \Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchronizer}).
 */
final class SynchronizationCommandHook implements CommandHookInterface
{
    private ?StaleTranslationFinder $resolvedStaleTranslationFinder = null;

    public function __construct(
        private readonly bool $enabled,
        private readonly SynchronizationRules $rules,
        private readonly ContentGraphReadModelInterface $contentGraphReadModel,
        private readonly NodeTypeManager $nodeTypeManager,
        private readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        private readonly ContentRepositoryId $contentRepositoryId,
        private readonly StalePropertyCommandBuilder $stalePropertyCommandBuilder,
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
    private function commandsForRule(SynchronizationRule $rule): array
    {
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceDimension]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetDimension]);
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

        $sourceSubgraph = $this->contentGraphReadModel
            ->getContentGraph($targetWorkspace)
            ->getSubgraph($sourceDsp, VisibilityConstraints::withoutRestrictions());
        $targetSubgraph = $this->contentGraphReadModel
            ->getContentGraph($targetWorkspace)
            ->getSubgraph($targetDsp, VisibilityConstraints::withoutRestrictions());

        // Collect each command paired with the source-tree depth of the node it acts on, so the
        // batch can be ordered ancestor-before-descendant below.
        /** @var list<array{depth:int,command:CommandInterface}> $plannedCommands */
        $plannedCommands = [];
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
            // Content scope only mirrors nodes whose containing Document already exists in the
            // target dimension; Documents are never created automatically. For a Document node the
            // closest Document is itself — so a Document missing in the target is left alone (no
            // auto-create), while one that already exists is still (re-)translated when stale.
            if ($rule->scope === SynchronizationScope::Content) {
                $documentNode = $sourceSubgraph->findClosestNode(
                    $sourceNode->aggregateId,
                    FindClosestNodeFilter::create(nodeTypes: 'Neos.Neos:Document'),
                );
                if ($documentNode === null || $targetSubgraph->findNodeById($documentNode->aggregateId) === null) {
                    continue;
                }
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
                    $plannedCommands[] = [
                        'depth' => $this->treeDepthOf($sourceSubgraph, $stale->nodeAggregateId),
                        'command' => $command,
                    ];
                }
                continue;
            }
            // Tethered children are created together with their non-tethered ancestor's variant —
            // the existing TranslationCommandHook handles the cascade, so we don't emit a
            // CreateNodeVariant for them ourselves.
            if ($sourceNode->classification->isTethered()) {
                continue;
            }
            $plannedCommands[] = [
                'depth' => $this->treeDepthOf($sourceSubgraph, $stale->nodeAggregateId),
                'command' => CreateNodeVariant::create(
                    $targetWorkspace,
                    $stale->nodeAggregateId,
                    $sourceNode->originDimensionSpacePoint,
                    $targetOrigin,
                ),
            ];
        }

        // Stale records arrive in primary-key order, not hierarchical order. A descendant's
        // `CreateNodeVariant` must not be dispatched before the ancestor variant that materialises
        // its (tethered) parent in the target dimension. Sorting by source-tree depth (PHP's sort
        // is stable since 8.0) yields a valid top-down order without walking the whole tree.
        usort($plannedCommands, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);

        return array_map(static fn (array $planned): CommandInterface => $planned['command'], $plannedCommands);
    }

    /**
     * Distance of the node from its root aggregate in the source subgraph (root = 0, its children
     * = 1, …). Used purely to order the synchronization commands ancestor-before-descendant.
     */
    private function treeDepthOf(ContentSubgraphInterface $sourceSubgraph, NodeAggregateId $nodeAggregateId): int
    {
        return $sourceSubgraph->findAncestorNodes($nodeAggregateId, FindAncestorNodesFilter::create())->count();
    }
}
