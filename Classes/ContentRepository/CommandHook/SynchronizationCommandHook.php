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
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
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
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SynchronizationMode;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationRules;
use Sitegeist\LostInTranslation\Domain\SynchronizationScope;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * Command hook that fires the auto-sync rules from `Sitegeist.LostInTranslation.nodeTranslation.synchronization`
 * after a workspace publish.
 *
 * Automatic synchronization is always stale-driven: for every rule whose `sourceWorkspaceName` matches the publish
 * target, the hook iterates the stale-translation records sitting at the rule's
 * `(targetWorkspaceName, targetDimension)` and emits one command per record:
 *  - **target variant missing** → `CreateNodeVariant`; the existing {@see TranslationCommandHook} cascades the
 *    translated `SetNodeProperties` automatically.
 *  - **target variant exists** → a translated `SetNodeProperties` built by {@see StalePropertyCommandBuilder}.
 *
 * The rule's {@see SynchronizationScope} gates which records are acted on:
 *  - {@see SynchronizationScope::Document} mirrors the whole structure (documents AND content).
 *  - {@see SynchronizationScope::Content} only acts on records whose containing Document already exists in the target
 *    dimension, and never creates Document variants automatically.
 *
 * Emitted commands are ordered ancestor-before-descendant (by source-tree depth) so a parent `CreateNodeVariant` —
 * which materialises tethered descendants such as a document's content collection — is dispatched before a deeper
 * node's own `CreateNodeVariant`.
 *
 * The hook only reads the {@see StaleTranslationFinder} (the projection has already caught up by the time
 * `onAfterHandle` runs, per the CR contract) and returns commands; it does not dispatch directly. AI authorship is
 * flagged via {@see AISystemTranslationRuntimeState} so the returned commands' events are attributed to the AI service
 * rather than the publishing editor.
 *
 * **AI authorship across the cascade.** {@see TranslationCommandHook::onAfterHandle} unconditionally resets the AI
 * runtime state at the start of every entry; for `CreateNodeVariant` it then re-sets the state before cascading its
 * own translated `SetNodeProperties`, but for a `SetNodeProperties` command it does not. If our cascade contains a
 * `SetNodeProperties` (target variant exists branch) or two consecutive `CreateNodeVariant`s, the next command in the
 * queue would otherwise be handled with a null AI state and attributed to the publishing editor.
 *
 * We close that gap by tracking the cascade commands we emit and re-setting the AI state in {@see self::onBeforeHandle}
 * for as long as any of them are still pending. {@see self::onAfterHandle} detaches a command from the set once it is
 * processed; when the set drains the cascade is over and no further re-set happens.
 *
 * Walking the whole tree from the root (regardless of stale state) is deliberately NOT done here — that is the
 * separate, manual `synchronize --full` CLI command
 * ({@see \Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchronizer}).
 */
final class SynchronizationCommandHook implements CommandHookInterface
{
    private ?StaleTranslationReadModel $resolvedStaleTranslationReadModel = null;

    /**
     * Commands we have queued from a publish-driven cascade. Tracked by object identity so we re-set the AI
     * runtime state in `onBeforeHandle` for each of them, regardless of what other hooks do in between.
     *
     * @var \SplObjectStorage<CommandInterface,null>
     */
    private \SplObjectStorage $pendingCascadeCommands;

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
        $this->pendingCascadeCommands = new \SplObjectStorage();
    }

    private function staleTranslationReadModel(): StaleTranslationReadModel
    {
        if ($this->resolvedStaleTranslationReadModel === null) {
            $this->resolvedStaleTranslationReadModel = $this->contentRepositoryRegistry
                ->get($this->contentRepositoryId)
                ->projectionState(StaleTranslationReadModel::class);
        }
        return $this->resolvedStaleTranslationReadModel;
    }

    private function staleTranslationFinder(): StaleTranslationFinder
    {
        return $this->staleTranslationReadModel()->staleTranslationFinder;
    }

    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        // Re-set the AI runtime state for every command while a publish-driven cascade is still in flight (see class
        // docblock). TranslationCommandHook will reset it again at the start of its own `onAfterHandle`; we make sure
        // it is set when the auth provider reads it during command handling.
        if ($this->pendingCascadeCommands->count() > 0) {
            $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        }
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        // A cascade command we previously queued has just been processed — drop it from the pending set so the count
        // converges to zero once the cascade is fully drained.
        if ($this->pendingCascadeCommands->contains($command)) {
            $this->pendingCascadeCommands->detach($command);
        }

        if (!$this->enabled || $this->rules->isEmpty()) {
            return Commands::createEmpty();
        }
        // Both PublishWorkspace and PublishIndividualNodesFromWorkspace emit a WorkspaceWasPublished event with the
        // publish target; we treat them identically here.
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
            // Bring the rule's target workspace current with the just-published source on EVERY publish — including for
            // `ask` rules. This refreshes the stale-translation projection for the (cross-workspace) target so the
            // backend module reports what still needs syncing, instead of the target lagging until someone rebases it.
            // Returns false when the rule cannot run at all (target workspace missing, or not based on the source).
            if (!$this->rebaseTargetOntoSource($rule)) {
                continue;
            }
            // `ask` rules defer the actual translation to a deliberate manual sync (Neos UI prompt / backend module
            // "sync now") so the publish stays fast. The rebase above already refreshed their status; nothing more to
            // do inline.
            if ($rule->mode === SynchronizationMode::Ask) {
                continue;
            }
            foreach ($this->commandsForRule($rule) as $cmd) {
                $additionalCommands[] = $cmd;
            }
        }

        if ($additionalCommands === []) {
            return Commands::createEmpty();
        }

        // Fresh publish — reset the pending tracker. Any leftovers from a prior cascade that aborted mid-flight (e.g.
        // an exception) are discarded so they cannot keep the AI state set on this and subsequent user commands.
        $this->pendingCascadeCommands = new \SplObjectStorage();
        foreach ($additionalCommands as $cmd) {
            $this->pendingCascadeCommands->attach($cmd);
        }
        // Initial AI attribution for the first command — `onBeforeHandle` would re-set it anyway, but doing it here
        // makes the contract obvious without depending on hook ordering for the very first command in the cascade.
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());

        return Commands::fromArray($additionalCommands);
    }

    /**
     * Both PublishWorkspace and PublishIndividualNodesFromWorkspace emit exactly one WorkspaceWasPublished event with
     * the publish target workspace; we pick that up here. A publish that produced no event (or no
     * WorkspaceWasPublished) cannot drive sync.
     */
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
     * Bring the rule's target workspace current with its source so the inline sync (and the stale-translation
     * projection that the backend status reads) reflect the just-published source content. Returns false when the rule
     * cannot run at all and the caller must skip it:
     *  - the target workspace does not exist — it is NEVER auto-created here (materialising a workspace on every publish
     *    is the deliberate, manual editor/admin path's job, not the inline hook's);
     *  - (cross-workspace) the target is not based on the source, so it cannot be reconciled with it.
     *
     * For a same-workspace rule there is nothing to rebase. For a cross-workspace rule the target is force-rebased onto
     * the source: this brings the target's source dimension current with the published source (every source node exists
     * in the target, and the translation reads the latest source) and — via the projection's `replaceWorkspaceEntries`
     * on `WorkspaceWasRebased` — refreshes the target's stale rows. The target's own review edits are replayed on top;
     * genuinely-conflicting target changes are dropped (the published source wins).
     */
    private function rebaseTargetOntoSource(SynchronizationRule $rule): bool
    {
        $sourceWorkspace = WorkspaceName::fromString($rule->sourceWorkspaceName);
        $targetWorkspace = WorkspaceName::fromString($rule->targetWorkspaceName);

        $targetWorkspaceModel = $this->contentGraphReadModel->findWorkspaceByName($targetWorkspace);
        if ($targetWorkspaceModel === null) {
            return false;
        }
        if ($targetWorkspace->equals($sourceWorkspace)) {
            return true;
        }
        if ($targetWorkspaceModel->baseWorkspaceName === null || !$targetWorkspaceModel->baseWorkspaceName->equals($sourceWorkspace)) {
            return false;
        }
        $this->contentRepositoryRegistry->get($this->contentRepositoryId)->handle(
            RebaseWorkspace::create($targetWorkspace)
                ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE)
        );
        return true;
    }

    /**
     * @return list<CommandInterface>
     */
    private function commandsForRule(SynchronizationRule $rule): array
    {
        // The target workspace has already been validated and (cross-workspace) rebased onto the source by
        // {@see self::rebaseTargetOntoSource()} before this is called, so we read the current, reconciled state here.
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

        // Collect each command paired with the source-tree depth of the node it acts on, so the batch can be ordered
        // ancestor-before-descendant below.
        /** @var list<array{depth:int,command:CommandInterface}> $plannedCommands */
        $plannedCommands = [];
        foreach ($this->staleTranslationFinder()->findAll() as $stale) {
            if (!$stale->workspaceName->equals($targetWorkspace)) {
                continue;
            }
            if ($stale->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            $sourceNode = $sourceSubgraph->findNodeById($stale->nodeAggregateId);
            // Source variant not present in the target workspace at the source dimension — nothing to translate from.
            // Leave the stale row alone.
            if ($sourceNode === null) {
                continue;
            }
            // Content scope only mirrors nodes whose containing Document already exists in the target dimension;
            // Documents are never created automatically. For a Document node the closest Document is itself — so a
            // Document missing in the target is left alone (no auto-create), while one that already exists is still
            // (re-)translated when stale.
            if ($rule->scope === SynchronizationScope::Content) {
                // `findClosestNode` walks `self -> ancestors` and returns the first match — so for a Document node it
                // returns the node itself. A Document absent from the target therefore falls through to the `continue`
                // (no auto-create), while a Document already present passes the gate and gets re-translated like any
                // other matching node.
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
                } else {
                    // Target variant exists but there is nothing translatable to set (the source property was unset,
                    // or holds a value no connector handles): no SetNodeProperties — hence no NodePropertiesWereSet —
                    // will ever fire to clear this row. The projection has already caught up for the publish, so prune
                    // the now-satisfied row directly instead of letting it linger and re-no-op on every publish.
                    $this->staleTranslationReadModel()->staleTranslationMaintenance->removeStaleRow(
                        $stale->workspaceName,
                        $stale->nodeAggregateId,
                        $stale->originDimensionSpacePoint,
                    );
                }
                continue;
            }
            // Tethered children are created together with their non-tethered ancestor's variant — the existing
            // TranslationCommandHook handles the cascade, so we don't emit a CreateNodeVariant for them ourselves.
            if ($sourceNode->classification->isTethered()) {
                // When the tethered node's target variant is absent yet its ancestor already exists in the target, no
                // CreateNodeVariant will ever materialise it (the cascade only fires from a non-tethered ancestor's
                // creation, and that ancestor is already present). With no properties flagged there is nothing to set
                // either — the row can never be cleared by an event and would re-no-op on every publish. Prune it
                // directly, consistent with the target-exists escape hatch above.
                $parentNode = $sourceSubgraph->findParentNode($sourceNode->aggregateId);
                if (
                    $stale->propertyNames->isEmpty()
                    && $parentNode !== null
                    && $targetSubgraph->findNodeById($parentNode->aggregateId) !== null
                ) {
                    $this->staleTranslationReadModel()->staleTranslationMaintenance->removeStaleRow(
                        $stale->workspaceName,
                        $stale->nodeAggregateId,
                        $stale->originDimensionSpacePoint,
                    );
                }
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

        // Stale records arrive in primary-key order, not hierarchical order. A descendant's `CreateNodeVariant` must
        // not be dispatched before the ancestor variant that materialises its (tethered) parent in the target
        // dimension. Sorting by source-tree depth (PHP's sort is stable since 8.0) yields a valid top-down order
        // without walking the whole tree.
        usort($plannedCommands, static fn (array $a, array $b): int => $a['depth'] <=> $b['depth']);

        return array_map(static fn (array $planned): CommandInterface => $planned['command'], $plannedCommands);
    }

    /**
     * Distance of the node from its root aggregate in the source subgraph (root = 0, its children = 1, …). Used
     * purely to order the synchronization commands ancestor-before-descendant.
     */
    private function treeDepthOf(ContentSubgraphInterface $sourceSubgraph, NodeAggregateId $nodeAggregateId): int
    {
        return $sourceSubgraph->findAncestorNodes($nodeAggregateId, FindAncestorNodesFilter::create())->count();
    }
}
