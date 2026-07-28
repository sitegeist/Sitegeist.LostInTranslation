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
use Neos\ContentRepository\Core\Feature\NodeRemoval\Command\RemoveNodeAggregate;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\TagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Command\UntagSubtree;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasTagged;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Event\SubtreeWasUntagged;
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
use Neos\ContentRepository\Core\SharedModel\Node\NodeVariantSelectionStrategy;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\CrossWorkspaceSynchronizationTarget;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\NodeTreeDepth;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SourceRemovalBehavior;
use Sitegeist\LostInTranslation\Domain\SourceTaggingBehavior;
use Sitegeist\LostInTranslation\Domain\StaleRecordReconciler;
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
 * for exactly those commands — matched by object identity, not by "is a cascade in flight".
 * {@see self::onAfterHandle} detaches a command once it is processed, and empties the tracker at the start of every
 * publish. Identity matching is what makes an aborted cascade harmless: a command whose handling threw is never
 * detached, but it can never match a later, unrelated command either.
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
        // Re-set the AI runtime state for each command WE queued from a publish-driven cascade (see class docblock).
        // TranslationCommandHook resets it again at the start of its own `onAfterHandle`; we make sure it is set when
        // the auth provider reads it during command handling.
        //
        // Matching on MEMBERSHIP (object identity) rather than "is a cascade in flight" is load-bearing. If a cascade
        // command throws, `onAfterHandle` never runs for it and it stays attached; a `count() > 0` test would then
        // keep flagging every later, unrelated command as AI-authored — and because
        // {@see AISystemTranslationRuntimeState} also disables authorization checks, run it with those checks off.
        // Identity can only ever match a command this hook queued, so a leftover is inert.
        //
        // This is safe for the cascade-of-a-cascade too: the translated `SetNodeProperties` that
        // {@see TranslationCommandHook} emits for our `CreateNodeVariant` is not ours, but that hook sets the AI state
        // for it itself before returning it.
        if ($this->pendingCascadeCommands->contains($command)) {
            $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        }
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        // A cascade command we previously queued has just been processed — drop it from the pending set so it stops
        // being re-flagged as AI-authored.
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

        // A publish is never part of a cascade, so anything still attached here is a leftover from an earlier cascade
        // that aborted mid-flight (on an exception `onAfterHandle` never runs for the remaining commands). Drop it as
        // soon as we know this is a publish — ahead of the remaining early returns — so the tracker cannot accumulate
        // command objects across publishes.
        $this->pendingCascadeCommands = new \SplObjectStorage();

        // The command itself does not carry the publish target — read it from the resulting event.
        $publicationTarget = $this->findPublicationTarget($events);
        if ($publicationTarget === null) {
            return Commands::createEmpty();
        }

        $matchingRules = $this->rules->forPublicationTarget($publicationTarget);
        if ($matchingRules->isEmpty()) {
            return Commands::createEmpty();
        }

        // Source-language node removals and subtree-tag changes (e.g. hide/show) carried by THIS publish, collected in a
        // single pass. Each `remove-target` / `sync-to-target` rule below filters them to the ones that actually touched
        // its source dimension. Both empty for an ordinary translate-only publish.
        $mirrorEvents = $this->collectMirrorEvents($events);

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
            // do inline — removals and tag changes are reconciled by that same manual sync too.
            if ($rule->mode === SynchronizationMode::Ask) {
                continue;
            }

            // Read the rule's source + target subgraphs ONCE here and pass them down, so the translation, tagging and
            // removal passes do not each re-fetch them on this (always-on) publish path.
            $contentGraph = $this->contentGraphReadModel->getContentGraph(WorkspaceName::fromString($rule->targetWorkspaceName));
            $sourceSubgraph = $contentGraph->getSubgraph(
                DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceDimension]),
                VisibilityConstraints::withoutRestrictions(),
            );
            $targetSubgraph = $contentGraph->getSubgraph(
                DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetDimension]),
                VisibilityConstraints::withoutRestrictions(),
            );

            $translationCommands = $this->commandsForRule($rule, $sourceSubgraph, $targetSubgraph);
            // Mirror source-language tag changes when the rule opts in. The plannedVariantCreationIds let us also tag a
            // node created in THIS publish — its variant does not exist yet when we build the batch, but the CR
            // dispatches the create before our tag (see the fixed assembly order below).
            $tagCommands = $rule->onSourceTagging === SourceTaggingBehavior::SyncToTarget
                ? $this->taggingCommandsForRule($rule, $mirrorEvents['taggings'], $this->plannedVariantCreationIds($translationCommands), $targetSubgraph)
                : [];
            // Mirror source-language deletions when the rule opts in. Cheap and precise: only this publish's own removal
            // events are inspected, never walking the target tree (that is the manual sync's job).
            $removalCommands = $rule->onSourceRemoval === SourceRemovalBehavior::RemoveTarget
                ? $this->removalCommandsForRule($rule, $mirrorEvents['removals'], $targetSubgraph)
                : [];

            // Assemble in a FIXED order — this is the load-bearing ordering contract, kept in one place rather than
            // relying on the append order of scattered branches. The CR dispatches the returned batch sequentially:
            //  1. translations (a CreateNodeVariant must materialise a variant before it can be tagged),
            //  2. tag changes (a node tagged AND removed in one publish must be tagged while it still exists),
            //  3. removals (each cascades its target subtree away).
            foreach ([...$translationCommands, ...$tagCommands, ...$removalCommands] as $cmd) {
                $additionalCommands[] = $cmd;
            }
        }

        if ($additionalCommands === []) {
            return Commands::createEmpty();
        }

        // Track this publish's cascade (the tracker was already emptied above, at the start of the publish).
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
     * Pick the source-side structural events out of the publish in a single pass: node removals
     * ({@see NodeAggregateWasRemoved}) and subtree-tag changes ({@see SubtreeWasTagged} / {@see SubtreeWasUntagged},
     * incl. the hide/show `disabled` tag). Both `PublishWorkspace` and `PublishIndividualNodesFromWorkspace` republish
     * the affected node events onto the target workspace alongside the `WorkspaceWasPublished` event, so a source-side
     * removal/tag surfaces here as part of the publish delta.
     *
     * @return array{removals: list<NodeAggregateWasRemoved>, taggings: list<SubtreeWasTagged|SubtreeWasUntagged>}
     */
    private function collectMirrorEvents(PublishedEvents $events): array
    {
        $removals = [];
        $taggings = [];
        foreach ($events as $event) {
            if ($event instanceof NodeAggregateWasRemoved) {
                $removals[] = $event;
            } elseif ($event instanceof SubtreeWasTagged || $event instanceof SubtreeWasUntagged) {
                $taggings[] = $event;
            }
        }
        return ['removals' => $removals, 'taggings' => $taggings];
    }

    /**
     * The node aggregate ids that a {@see CreateNodeVariant} in this batch will materialise in the target dimension —
     * used so a node created AND tagged in the same publish can still be tagged (its variant exists by the time the CR
     * dispatches our tagging commands).
     *
     * @param list<CommandInterface> $translationCommands
     * @return array<string,true>
     */
    private function plannedVariantCreationIds(array $translationCommands): array
    {
        $ids = [];
        foreach ($translationCommands as $command) {
            if ($command instanceof CreateNodeVariant) {
                $ids[$command->nodeAggregateId->value] = true;
            }
        }
        return $ids;
    }

    /**
     * Mirror this publish's source-language subtree-tag changes into the rule's target dimension. Only called for rules
     * with {@see SourceTaggingBehavior::SyncToTarget}.
     *
     * For each tag/untag that affected the rule's SOURCE dimension, emit the matching {@see TagSubtree} /
     * {@see UntagSubtree} for the target variant. Not gated by {@see SynchronizationScope} — a hidden Document should
     * hide in the target whether the rule mirrors structure or only content. The emit is idempotent: the CR throws when
     * tagging an already-explicitly-tagged node (or untagging one that is not), so we skip a command whose target is
     * already in the desired explicit state — which also makes re-publishes safe.
     *
     * `$plannedVariantCreationIds` are the nodes a `CreateNodeVariant` earlier in THIS batch will materialise. Their
     * target variant does not exist yet when we build the batch, but the CR dispatches the create before our tagging
     * commands, so a freshly-created variant can still be tagged this publish (it starts untagged, so the add is safe;
     * there is nothing to untag).
     *
     * @param list<SubtreeWasTagged|SubtreeWasUntagged> $taggingEvents
     * @param array<string,true> $plannedVariantCreationIds
     * @return list<TagSubtree|UntagSubtree>
     */
    private function taggingCommandsForRule(
        SynchronizationRule $rule,
        array $taggingEvents,
        array $plannedVariantCreationIds,
        ContentSubgraphInterface $targetSubgraph,
    ): array {
        if ($taggingEvents === []) {
            return [];
        }
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceDimension]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetDimension]);
        $targetWorkspace = WorkspaceName::fromString($rule->targetWorkspaceName);

        $commands = [];
        foreach ($taggingEvents as $tagging) {
            // Only mirror tag changes that touched the SOURCE language. A tag applied directly to the target variant
            // leaves the source DSP out of the affected set and must not bounce back.
            if (!$tagging->affectedDimensionSpacePoints->contains($sourceDsp)) {
                continue;
            }
            $targetNode = $targetSubgraph->findNodeById($tagging->nodeAggregateId);
            if ($targetNode === null) {
                // The target variant does not exist yet. If a CreateNodeVariant in this batch will materialise it (the
                // CR dispatches that create before this command), mirror an ADD onto the soon-to-exist, untagged
                // variant. An untag has nothing to act on and is skipped. A node neither present nor being created
                // cannot be tagged here — a later tag-only publish or a manual sync reconciles it.
                if ($tagging instanceof SubtreeWasTagged && isset($plannedVariantCreationIds[$tagging->nodeAggregateId->value])) {
                    $commands[] = TagSubtree::create(
                        $targetWorkspace,
                        $tagging->nodeAggregateId,
                        $targetDsp,
                        NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                        $tagging->tag,
                    );
                }
                continue;
            }
            // Compare against the target's EXPLICIT tags (inherited ones cannot be (un)tagged): skip when already in the
            // desired state so we never trip SubtreeIsAlreadyTagged / SubtreeIsNotTagged.
            $explicitlyTagged = $targetNode->tags->withoutInherited()->contain($tagging->tag);
            if ($tagging instanceof SubtreeWasTagged) {
                if ($explicitlyTagged) {
                    continue;
                }
                $commands[] = TagSubtree::create(
                    $targetWorkspace,
                    $tagging->nodeAggregateId,
                    $targetDsp,
                    NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                    $tagging->tag,
                );
            } else {
                if (!$explicitlyTagged) {
                    continue;
                }
                $commands[] = UntagSubtree::create(
                    $targetWorkspace,
                    $tagging->nodeAggregateId,
                    $targetDsp,
                    NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
                    $tagging->tag,
                );
            }
        }
        return $commands;
    }

    /**
     * Mirror this publish's source-language removals into the rule's target dimension — the deletion-side counterpart of
     * {@see self::commandsForRule()}. Only called for rules with {@see SourceRemovalBehavior::RemoveTarget}.
     *
     * For each removal that actually touched the rule's SOURCE dimension we emit one `RemoveNodeAggregate` for the
     * target variant. Removing the (subtree-root) target node is enough — the Content Repository cascades descendant
     * removal in the target dimension, mirroring how the CR emits only the one removal event on the source side.
     *
     * @param list<NodeAggregateWasRemoved> $removalEvents
     * @return list<RemoveNodeAggregate>
     */
    private function removalCommandsForRule(
        SynchronizationRule $rule,
        array $removalEvents,
        ContentSubgraphInterface $targetSubgraph,
    ): array {
        if ($removalEvents === []) {
            return [];
        }
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceDimension]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetDimension]);
        $targetWorkspace = WorkspaceName::fromString($rule->targetWorkspaceName);

        // First pass: collect every removal that should mirror into the target — source language affected, target
        // variant still present, scope permits removing this node type. Keep the ids so the second pass can drop
        // descendants.
        /** @var list<NodeAggregateId> $candidates */
        $candidates = [];
        /** @var array<string,true> $candidateIds */
        $candidateIds = [];
        foreach ($removalEvents as $removed) {
            // Only mirror removals that affected the SOURCE language. A removal that touched only the target variant
            // (an editor deleting the translation directly) leaves the source DSP out of the affected set and must not
            // bounce back into the target.
            if (!$removed->affectedCoveredDimensionSpacePoints->contains($sourceDsp)) {
                continue;
            }
            // The target variant may already be gone — e.g. the source removal used `allVariants`, which the
            // rebase-onto-source above already replayed onto the target dimension. Nothing left to mirror.
            $targetNode = $targetSubgraph->findNodeById($removed->nodeAggregateId);
            if ($targetNode === null) {
                continue;
            }
            $nodeType = $this->nodeTypeManager->getNodeType($targetNode->nodeTypeName);
            $isDocument = $nodeType !== null && $nodeType->isOfType('Neos.Neos:Document');
            if (!$rule->scope->mayRemoveNode($isDocument)) {
                continue;
            }
            $candidates[] = $removed->nodeAggregateId;
            $candidateIds[$removed->nodeAggregateId->value] = true;
        }

        // Second pass: keep only subtree-root removals. A single `RemoveNodeAggregate` cascades the target subtree, and
        // the CR emits a standalone `NodeAggregateWasRemoved` only for EXPLICITLY removed aggregates (never for
        // cascade-removed children). So when an editor removed both an ancestor and one of its descendants in the same
        // publish, emitting a removal for each would make the descendant's command target an aggregate the ancestor's
        // cascade already deleted — aborting with `NodeAggregateCurrentlyDoesNotExist`. Dropping any candidate that has
        // an ancestor in the same batch (resolved against the TARGET subgraph, whose hierarchy may diverge from the
        // source) leaves only the roots; the cascade removes the rest.
        $commands = [];
        foreach ($candidates as $candidate) {
            if ($this->hasAncestorIn($targetSubgraph, $candidate, $candidateIds)) {
                continue;
            }
            $commands[] = RemoveNodeAggregate::create(
                $targetWorkspace,
                $candidate,
                $targetDsp,
                // Mirror "remove this node in this language": the target DSP and its specializations, leaving the
                // (already-removed-or-untouched) source language peer alone.
                NodeVariantSelectionStrategy::STRATEGY_ALL_SPECIALIZATIONS,
            );
        }
        return $commands;
    }

    /**
     * Whether any ancestor of `$nodeAggregateId` in the target subgraph is itself in `$candidateIds` — i.e. this node
     * would be cascade-removed by a removal already planned for one of its ancestors.
     *
     * @param array<string,true> $candidateIds
     */
    private function hasAncestorIn(
        ContentSubgraphInterface $targetSubgraph,
        NodeAggregateId $nodeAggregateId,
        array $candidateIds,
    ): bool {
        foreach ($targetSubgraph->findAncestorNodes($nodeAggregateId, FindAncestorNodesFilter::create()) as $ancestor) {
            if (isset($candidateIds[$ancestor->aggregateId->value])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bring the rule's target workspace current with its source so the inline sync (and the stale-translation
     * projection that the backend status reads) reflect the just-published source content — see
     * {@see CrossWorkspaceSynchronizationTarget}. Returns false when the rule cannot run at all and the caller must
     * skip it (target workspace missing, or — cross-workspace — not based on the source).
     */
    private function rebaseTargetOntoSource(SynchronizationRule $rule): bool
    {
        return CrossWorkspaceSynchronizationTarget::prepare(
            $this->contentRepositoryRegistry->get($this->contentRepositoryId),
            WorkspaceName::fromString($rule->sourceWorkspaceName),
            WorkspaceName::fromString($rule->targetWorkspaceName),
        ) === null;
    }

    /**
     * @return list<CommandInterface>
     */
    private function commandsForRule(
        SynchronizationRule $rule,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
    ): array {
        // The target workspace has already been validated and (cross-workspace) rebased onto the source by
        // {@see self::rebaseTargetOntoSource()} before this is called, so we read the current, reconciled state here.
        // The source/target subgraphs are fetched once by the caller and passed in.
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->sourceDimension]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimension->id->value => $rule->targetDimension]);
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDsp);
        $targetWorkspace = WorkspaceName::fromString($rule->targetWorkspaceName);

        $languagePair = $this->dimensionValueDirectiveFactory->tryResolveLanguagePair(
            $this->languageDimension,
            $sourceDsp,
            $targetDsp,
        );
        if ($languagePair === null) {
            return [];
        }
        $sourceDeepl = $languagePair->sourceLanguage;
        $targetDeepl = $languagePair->targetLanguage;

        // Collect each command paired with the source-tree depth of the node it acts on, so the batch can be ordered
        // ancestor-before-descendant below.
        /** @var list<array{depth:int,command:CommandInterface}> $plannedCommands */
        $plannedCommands = [];
        foreach ($this->staleTranslationFinder()->findByWorkspaceAndOrigin($targetWorkspace, $targetOrigin) as $stale) {
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
                        'depth' => NodeTreeDepth::of($sourceSubgraph, $stale->nodeAggregateId),
                        'command' => $command,
                    ];
                } else {
                    // Target variant exists but there is nothing translatable to set: no SetNodeProperties — hence no
                    // NodePropertiesWereSet — will ever clear this row, so prune it (see StaleRecordReconciler).
                    StaleRecordReconciler::pruneIfUnsatisfiable(
                        $this->staleTranslationReadModel()->staleTranslationMaintenance,
                        $targetWorkspace,
                        $stale,
                        $sourceNode,
                        $sourceSubgraph,
                        $targetSubgraph,
                    );
                }
                continue;
            }
            // Tethered children are created together with their non-tethered ancestor's variant — the existing
            // TranslationCommandHook handles the cascade, so we don't emit a CreateNodeVariant for them ourselves. When
            // such a tethered no-op row can never be cleared by an event, StaleRecordReconciler prunes it.
            if ($sourceNode->classification->isTethered()) {
                StaleRecordReconciler::pruneIfUnsatisfiable(
                    $this->staleTranslationReadModel()->staleTranslationMaintenance,
                    $targetWorkspace,
                    $stale,
                    $sourceNode,
                    $sourceSubgraph,
                    $targetSubgraph,
                );
                continue;
            }
            $plannedCommands[] = [
                'depth' => NodeTreeDepth::of($sourceSubgraph, $stale->nodeAggregateId),
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
}
