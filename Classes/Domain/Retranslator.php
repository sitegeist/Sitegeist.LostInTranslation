<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;

/**
 * Driver that brings a target-language dimension subtree back in sync with its source (reference)
 * language, by dispatching:
 *
 *  - `SetNodeProperties` for existing target variants whose translated properties are stale
 *    (per {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}).
 *  - `CreateNodeVariant` for source nodes that have no target variant yet. The
 *    {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
 *    then cascades translation onto the freshly-created variant (including tethered children).
 *
 * Stale-property commands are dispatched while {@see AISystemTranslationRuntimeState} marks the AI
 * as the actor so event metadata is attributed to the AI service, not the editor.
 */
class Retranslator
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected StalePropertyCommandBuilder $stalePropertyCommandBuilder;

    #[Flow\Inject]
    protected SecurityContext $securityContext;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    /**
     * Retranslate the subtree below `$nodeAggregateId` into `$targetDimensionSpacePoint`.
     *
     * The source DSP is derived from the target via the `referenceLanguage` config on the target
     * preset — callers do not pass it. Calling with the source language itself (no `referenceLanguage`)
     * is a legitimate no-op, returning `RetranslationResult::skipped(...)` rather than throwing.
     *
     * `$sourceWorkspaceName` defaults to `$workspaceName`. When it differs, the source subtree (and the stale
     * records flagged against it) are read from `$sourceWorkspaceName`, while every emitted command is dispatched
     * into `$workspaceName` — the cross-workspace case (e.g. read `live`, write `de-review`). The target workspace
     * must contain the source-dimension nodes (true when forked from the source workspace).
     *
     * Best-effort: every misconfiguration returns `skipped`; callers distinguish real work from
     * no-ops via the dispatch counts on the returned {@see RetranslationResult}. Repeated invocations
     * are idempotent.
     */
    public function retranslateNode(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePoint $targetDimensionSpacePoint,
        ?WorkspaceName $sourceWorkspaceName = null,
    ): RetranslationResult {
        $sourceWorkspaceName ??= $workspaceName;
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);

        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return RetranslationResult::skipped(sprintf(
                'language dimension "%s" not configured in CR "%s"',
                $this->languageDimensionName,
                $contentRepositoryId->value,
            ));
        }

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($sourceDimensionSpacePoint === null) {
            return RetranslationResult::skipped(sprintf(
                'no referenceLanguage configured for target DSP %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDeeplLanguage = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        )?->deeplSourceId;
        $targetDeeplLanguage = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        )?->deeplTargetId;
        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return RetranslationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        // Source subtree is read from the source workspace; target-variant existence from the (possibly different)
        // target workspace. They are the same graph in the common single-workspace case.
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);
        $targetContentGraph = $cr->getContentGraph($workspaceName);
        $sourceSubgraph = $sourceContentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $targetContentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        // Scope to the current document: nested documents are out of scope for a retranslation run.
        // The entry node itself is always returned by `findSubtree`, so a document entry still gets its own properties retranslated.
        $sourceSubtree = $sourceSubgraph->findSubtree(
            $nodeAggregateId,
            FindSubtreeFilter::create(
                nodeTypes: NodeTypeCriteria::createWithAllowedNodeTypeNames(
                    NodeTypeNames::fromStringArray(['Neos.Neos:ContentCollection', 'Neos.Neos:Content'])
                ),
            ),
        );
        if ($sourceSubtree === null) {
            return RetranslationResult::skipped(sprintf(
                'source node %s not found in DSP %s',
                $nodeAggregateId->value,
                $sourceDimensionSpacePoint->toJson(),
            ));
        }

        // Pre-fetch stale records keyed by aggregate id for O(1) lookup during the walk.
        // The finder takes the *source* subtree (for the node id list) but filters by the *target*
        // origin dsp hash — that's where stale records live.
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $staleTranslationReadModel = $cr->projectionState(StaleTranslationReadModel::class);
        $staleTranslations = $staleTranslationReadModel
            ->staleTranslationFinder
            ->findBySubtree($sourceSubtree, $targetOrigin);
        $staleByNodeAggregateId = [];
        foreach ($staleTranslations as $staleTranslation) {
            $staleByNodeAggregateId[$staleTranslation->nodeAggregateId->value] = $staleTranslation;
        }

        // Iterative depth-first pre-order walk over the source subtree. For each node:
        //   - Emit SetNodeProperties if a stale record exists AND the target variant already exists.
        //     (Missing target → defer to CreateNodeVariant + hook cascade.)
        //   - Emit CreateNodeVariant if the target variant is missing AND the node is non-tethered.
        //     Tethered descendants come along automatically with their ancestor variant.
        //
        // Children are pushed onto the stack in reverse so they pop in declaration order
        // (preserves pre-order; matches event-index assertions in the Behat tests).
        $nodeTypeManager = $cr->getNodeTypeManager();
        $stalePropertyCommands = [];
        $variantCommands = [];
        /** @var list<StaleTranslation> $satisfiedStaleRecords */
        $satisfiedStaleRecords = [];
        $stack = [$sourceSubtree];
        while ($stack !== []) {
            $currentSubtree = array_pop($stack);
            $sourceNode = $currentSubtree->node;
            $existsInTarget = $targetSubgraph->findNodeById($sourceNode->aggregateId) !== null;

            $stale = $staleByNodeAggregateId[$sourceNode->aggregateId->value] ?? null;
            if ($stale !== null && $existsInTarget) {
                $command = $this->stalePropertyCommandBuilder->buildSetNodeProperties(
                    nodeTypeManager: $nodeTypeManager,
                    sourceNode: $sourceNode,
                    stalePropertyNames: $stale->propertyNames,
                    // Use the OriginDimensionSpacePoint from the stale record, not a freshly built
                    // one — it reflects where the variant actually lives (matters for spec/gen
                    // variants).
                    targetOrigin: $stale->originDimensionSpacePoint,
                    sourceDeeplLanguage: $sourceDeeplLanguage,
                    targetDeeplLanguage: $targetDeeplLanguage,
                    targetWorkspaceName: $workspaceName,
                );
                if ($command !== null) {
                    $stalePropertyCommands[] = $command;
                } else {
                    // The target variant exists but there is nothing translatable to set (the source property was
                    // unset, or holds a value no connector handles). No SetNodeProperties is dispatched, so the
                    // projection never sees a NodePropertiesWereSet to clear this row — it would otherwise linger
                    // forever and re-no-op on every run. The node is as in-sync as it can be, so prune the row.
                    $satisfiedStaleRecords[] = $stale;
                }
            } elseif (
                $stale !== null
                && $sourceNode->classification->isTethered()
                && $stale->propertyNames->isEmpty()
            ) {
                // Tethered node with a stale row but no target variant (the `$existsInTarget` arm above did not fire)
                // and no flagged properties. Its variant can only be materialised by a non-tethered ancestor's
                // CreateNodeVariant cascade — so when that ancestor already exists in the target, nothing will ever
                // create this node, and with no properties there is nothing to set anyway. No event will clear the
                // row; it would linger and re-no-op on every run. Treat as a no-op and prune it, consistent with the
                // target-exists escape hatch above.
                $parentNode = $sourceSubgraph->findParentNode($sourceNode->aggregateId);
                if ($parentNode !== null && $targetSubgraph->findNodeById($parentNode->aggregateId) !== null) {
                    $satisfiedStaleRecords[] = $stale;
                }
            }

            if (!$existsInTarget && !$sourceNode->classification->isTethered()) {
                $variantCommands[] = CreateNodeVariant::create(
                    $workspaceName,
                    $sourceNode->aggregateId,
                    $sourceNode->originDimensionSpacePoint,
                    OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint()),
                );
            }

            foreach (array_reverse([...$currentSubtree->children]) as $childSubtree) {
                $stack[] = $childSubtree;
            }
        }

        // AI synchronization is a system operation: it must write the translations into the target workspace
        // regardless of the workspace role of whoever triggered it (an editor publishing, or clicking "sync now",
        // need not have write access to e.g. `live`). We therefore dispatch with CR authorization checks disabled.
        // The writes remain bounded to translation commands for the configured/stale nodes.
        // Both command groups are dispatched as AI: this is a system translation operation, so every resulting event
        // (the structural NodePeerVariantWasCreated AND the translated NodePropertiesWereSet) is attributed to the AI
        // service rather than the editor who triggered the run — consistent with the publish-driven
        // SynchronizationCommandHook. The two groups act on disjoint nodes (SetNodeProperties for variants that already
        // exist in the target, CreateNodeVariant for those that do not), so their relative order is immaterial; only
        // the within-`$variantCommands` pre-order (ancestor before descendant) matters and is preserved by the walk.
        $this->securityContext->withoutAuthorizationChecks(function () use ($cr, $stalePropertyCommands, $variantCommands): void {
            foreach ($stalePropertyCommands as $command) {
                $this->dispatchAsAi($cr, $command);
            }
            foreach ($variantCommands as $command) {
                $this->dispatchAsAi($cr, $command);
            }
        });
        // Prune stale rows that no command could satisfy — see the no-op branch above. Done after dispatch (these
        // records never overlap the dispatched commands' nodes) via the maintenance API, the sanctioned escape hatch
        // for cleanup the projection's event-driven apply() path cannot perform on its own.
        //
        // Prune the row in the TARGET workspace we are reconciling — NOT `$stale->workspaceName`. The stale records
        // were read (via `findBySubtree`) from the SOURCE workspace, so in the cross-workspace case `$stale->workspaceName`
        // is the source (e.g. `live`); pruning it there would wrongly clear the source's own pending translation, which
        // this run never touched. In the single-workspace case source == target == `$workspaceName`, so this is
        // unchanged there.
        foreach ($satisfiedStaleRecords as $stale) {
            $staleTranslationReadModel->staleTranslationMaintenance->removeStaleRow(
                $workspaceName,
                $stale->nodeAggregateId,
                $stale->originDimensionSpacePoint,
            );
        }

        return new RetranslationResult(
            stalePropertyCommandsDispatched: count($stalePropertyCommands),
            variantCommandsDispatched: count($variantCommands),
        );
    }

    /**
     * Dispatch a command with AI authorship active. `try/finally` is load-bearing: an exception
     * inside `handle()` must still reset the singleton runtime state.
     */
    private function dispatchAsAi(ContentRepository $cr, CommandInterface $command): void
    {
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        try {
            $cr->handle($command);
        } finally {
            $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        }
    }
}
