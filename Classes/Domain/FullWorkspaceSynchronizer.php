<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Command\RebaseWorkspace;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Dto\RebaseErrorHandlingStrategy;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;

/**
 * Walks the entire source-dimension subgraph from each root aggregate downward and emits translation commands for every
 * translatable node — independent of the stale-translation projection. Where {@see WorkspaceSynchronizer} only acts on
 * records the projection has already flagged, this service treats every node in the source dimension as a candidate.
 *
 * Decision matrix per node (only when `directive->enabled`):
 *   - target variant absent + non-tethered → `CreateNodeVariant`; the
 *     {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} cascades a
 *     full-properties `SetNodeProperties` automatically.
 *   - target variant absent + tethered → skip; the tethered variant is created (and translated) by its non-tethered
 *     ancestor's `CreateNodeVariant` cascade.
 *   - target variant present + `$skipExisting` + no stale row → skip (keep manual edits).
 *   - target variant present otherwise (i.e. `!$skipExisting`, OR a stale record exists) → translated
 *     `SetNodeProperties` for *every* translatable property. This applies to tethered children too: once their variant
 *     exists, a property change on the source is refreshed directly. The stale-record case is the load-bearing
 *     override: a stale node is always refreshed even under `$skipExisting`.
 *
 * Sole entry point {@see self::synchronizeWorkspaceFull} (CLI `synchronize --full`) dispatches inline, delegating
 * per-node decisions to {@see self::decideCommandForNode} and traversal to {@see self::traverseSourceSubtrees}.
 *
 * Source and target workspace may differ: the source subtree is read from `sourceWorkspaceName` while every emitted
 * command (variant creation, property update) is dispatched into `targetWorkspaceName`. This supports workflows like a
 * full re-translation from published `live` into a `de-review` workspace without an intermediate publish.
 *
 * When source and target differ, the target workspace is first force-rebased onto its base (which must be the source
 * workspace). That brings the target's source dimension current with the source workspace — so the `CreateNodeVariant`
 * cascade (which reads the target's own source dimension) translates the latest source content — and materialises any
 * source nodes the target had not yet seen, so a node present only in the source no longer aborts the run. Conflicting
 * target-side changes are dropped (force); non-conflicting target-dimension review edits survive the rebase replay.
 */
class FullWorkspaceSynchronizer
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
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\Inject]
    protected ReviewWorkspaceProvisioner $reviewWorkspaceProvisioner;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    public function synchronizeWorkspaceFull(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $skipExisting = true,
        bool $useCache = true,
        bool $dryRun = false,
    ): WorkspaceSynchronizationResult {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
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
        $expectedSourceDsp = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($expectedSourceDsp === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'no referenceLanguage configured for target dimension %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }
        if (!$expectedSourceDsp->equals($sourceDimensionSpacePoint)) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'source dimension %s does not match configured referenceLanguage %s for target dimension %s',
                $sourceDimensionSpacePoint->toJson(),
                $expectedSourceDsp->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDeepl = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        )?->deeplSourceId;
        $targetDeepl = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        )?->deeplTargetId;
        if ($sourceDeepl === null || $targetDeepl === null) {
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        // Auto-create the target on first sync: a config rule (e.g. targetWorkspaceName "de-review") may point at a
        // workspace that does not exist yet. Create it as a shared review workspace based on live. In dry-run we leave
        // the CR untouched and just skip the dependent rebase below.
        $targetWorkspaceExists = $cr->findWorkspaceByName($targetWorkspaceName) !== null;
        if (!$targetWorkspaceExists && !$dryRun) {
            $this->reviewWorkspaceProvisioner->createSharedReviewWorkspace($contentRepositoryId, $targetWorkspaceName);
            $targetWorkspaceExists = true;
        }
        if (!$targetWorkspaceExists) {
            // dry-run against a not-yet-created target: the full walk below reads the target ContentGraph to decide
            // which variants exist, which would fault on a missing workspace. Report that it would be created instead.
            return WorkspaceSynchronizationResult::skipped(sprintf(
                'target workspace "%s" does not exist yet; it would be created as a shared review workspace based on live',
                $targetWorkspaceName->value,
            ));
        }

        // Cross-workspace only: bring the target current with its base (the source workspace) before reading it, so
        // the CreateNodeVariant cascade reads the latest source content and every source node exists in the target.
        // Conflicting target-side changes are dropped; review edits are preserved by the replay. A freshly auto-created
        // workspace is already current with its base, so the rebase is a harmless no-op there.
        if (!$sourceWorkspaceName->equals($targetWorkspaceName)) {
            $cr->handle(
                RebaseWorkspace::create($targetWorkspaceName)
                    ->withErrorHandlingStrategy(RebaseErrorHandlingStrategy::STRATEGY_FORCE)
            );
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        // Source structure + content are read from the source workspace; the target workspace only supplies the
        // "does the variant already exist" answer. They are the same graph in the common single-workspace case.
        $sourceContentGraph = $cr->getContentGraph($sourceWorkspaceName);
        $targetContentGraph = $cr->getContentGraph($targetWorkspaceName);
        // Stale records live alongside the source content (the projection records them in the workspace where the
        // source was edited), so the skip-existing lookup keys off the source workspace.
        $staleByNodeId = $this->collectStaleByNodeId($cr, $sourceContentGraph, $sourceWorkspaceName, $targetOrigin);
        $staleTranslationMaintenance = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationMaintenance;
        $nodeTypeManager = $cr->getNodeTypeManager();

        $sourceSubgraph = $sourceContentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $targetContentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $perNodeResults = [];
        foreach ($this->traverseSourceSubtrees($sourceContentGraph, $sourceSubgraph) as $node) {
            $command = $this->decideCommandForNode(
                $nodeTypeManager,
                $node,
                $targetWorkspaceName,
                $targetSubgraph,
                $targetOrigin,
                $staleByNodeId,
                $sourceDeepl,
                $targetDeepl,
                $skipExisting,
                $useCache,
            );
            if ($command === null) {
                // No command for this node. Two situations leave a stale row that no future event will ever clear, so
                // it would linger and re-no-op on every full sync — prune it directly, mirroring the stale-driven
                // Retranslator path. (A dry-run only reports, so it must not write.)
                //   (a) target variant exists but there was nothing translatable to set (every source property unset,
                //       or held a value no connector handles): no SetNodeProperties — hence no NodePropertiesWereSet.
                //   (b) tethered node whose target variant is absent while its ancestor already exists in the target,
                //       and the row flags no properties: no CreateNodeVariant will materialise it (tethered nodes only
                //       come along with a non-tethered ancestor's cascade, and that ancestor is already present), and
                //       there is nothing to set.
                $stale = $staleByNodeId[$node->aggregateId->value] ?? null;
                if (!$dryRun && $stale !== null) {
                    $targetExists = $targetSubgraph->findNodeById($node->aggregateId) !== null;
                    $parentNode = !$targetExists && $node->classification->isTethered() && $stale->propertyNames->isEmpty()
                        ? $sourceSubgraph->findParentNode($node->aggregateId)
                        : null;
                    $tetheredMissingNoop = $parentNode !== null
                        && $targetSubgraph->findNodeById($parentNode->aggregateId) !== null;
                    if ($targetExists || $tetheredMissingNoop) {
                        $staleTranslationMaintenance->removeStaleRow(
                            $stale->workspaceName,
                            $stale->nodeAggregateId,
                            $stale->originDimensionSpacePoint,
                        );
                    }
                }
                continue;
            }
            $isVariant = $command instanceof CreateNodeVariant;
            $perNodeResults[] = new PerNodeSynchronizationResult(
                $node->aggregateId,
                $dryRun
                    ? RetranslationResult::skipped('dry-run')
                    : new RetranslationResult(
                        stalePropertyCommandsDispatched: $isVariant ? 0 : 1,
                        variantCommandsDispatched: $isVariant ? 1 : 0,
                    ),
            );
            if (!$dryRun) {
                $this->dispatchAsAi($cr, $command);
            }
        }

        return new WorkspaceSynchronizationResult($perNodeResults);
    }

    /**
     * Decide the single command for a visited node, or null when it should be skipped (not translatable, a tethered
     * child with no target variant yet, already in sync under `$skipExisting`, or no translatable values).
     *
     * @param array<string, StaleTranslation> $staleByNodeId
     */
    private function decideCommandForNode(
        NodeTypeManager $nodeTypeManager,
        Node $node,
        WorkspaceName $targetWorkspaceName,
        ContentSubgraphInterface $targetSubgraph,
        OriginDimensionSpacePoint $targetOrigin,
        array $staleByNodeId,
        string $sourceDeepl,
        string $targetDeepl,
        bool $skipExisting,
        bool $useCache,
    ): ?CommandInterface {
        $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        if (!$directive->enabled) {
            return null;
        }

        if ($targetSubgraph->findNodeById($node->aggregateId) === null) {
            // No target variant yet. A tethered child cannot be created independently — it materialises with its
            // non-tethered ancestor's CreateNodeVariant cascade (which the TranslationCommandHook then translates),
            // so we skip emitting one here.
            if ($node->classification->isTethered()) {
                return null;
            }
            // The cascade also translates the full property set of the new variant. Dispatch into the target
            // workspace, varying from the source-dimension node that the target workspace shares with the source.
            return CreateNodeVariant::create(
                $targetWorkspaceName,
                $node->aggregateId,
                $node->originDimensionSpacePoint,
                $targetOrigin,
            );
        }

        // Target variant exists (true for tethered children once their ancestor was translated) — a SetNodeProperties
        // can refresh it directly, so tethered nodes are NOT excluded from this branch. Keep it untouched only when
        // keeping existing AND it is not stale — a stale record always forces a refresh.
        if ($skipExisting && !isset($staleByNodeId[$node->aggregateId->value])) {
            return null;
        }

        // Re-translate every translatable property (not just the stale slice).
        $allTranslatableNames = PropertyNames::fromArray(array_map(
            static fn ($t): PropertyName => $t->propertyName,
            iterator_to_array($directive->translatablePropertyNames),
        ));
        return $this->stalePropertyCommandBuilder->buildSetNodeProperties(
            nodeTypeManager: $nodeTypeManager,
            sourceNode: $node,
            stalePropertyNames: $allTranslatableNames,
            targetOrigin: $targetOrigin,
            sourceDeeplLanguage: $sourceDeepl,
            targetDeeplLanguage: $targetDeepl,
            useCache: $useCache,
            targetWorkspaceName: $targetWorkspaceName,
        );
    }

    /**
     * Depth-first pre-order traversal of every root aggregate's source-dimension subtree. Children are followed
     * unfiltered (Document/ContentCollection/Content alike), mirroring the legacy `translateCommand` walk.
     *
     * @return \Generator<Node>
     */
    private function traverseSourceSubtrees(
        \Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface $contentGraph,
        ContentSubgraphInterface $sourceSubgraph,
    ): \Generator {
        foreach ($contentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create()) as $rootAggregate) {
            $rootNode = $sourceSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            yield from $this->traverseSubtree($sourceSubgraph, $rootNode);
        }
    }

    /**
     * @return \Generator<Node>
     */
    private function traverseSubtree(ContentSubgraphInterface $sourceSubgraph, Node $node): \Generator
    {
        yield $node;
        foreach ($sourceSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
            yield from $this->traverseSubtree($sourceSubgraph, $childNode);
        }
    }

    /**
     * Pre-fetch the stale records for the (targetWorkspace, targetDSP) slice, keyed by node aggregate id. Used only for
     * the `skipExisting` decision. Orphan rows (whose aggregate no longer exists in the ContentGraph) are filtered out
     * so they cannot contaminate the lookup — see `lostintranslation:reconcile` for the cleanup path.
     *
     * @return array<string, StaleTranslation>
     */
    private function collectStaleByNodeId(
        ContentRepository $cr,
        \Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphInterface $contentGraph,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $targetOrigin,
    ): array {
        $staleByNodeId = [];
        foreach ($cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder->findAll() as $stale) {
            assert($stale instanceof StaleTranslation);
            if (!$stale->workspaceName->equals($targetWorkspaceName)) {
                continue;
            }
            if ($stale->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            if ($contentGraph->findNodeAggregateById($stale->nodeAggregateId) === null) {
                continue;
            }
            $staleByNodeId[$stale->nodeAggregateId->value] = $stale;
        }
        return $staleByNodeId;
    }

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
