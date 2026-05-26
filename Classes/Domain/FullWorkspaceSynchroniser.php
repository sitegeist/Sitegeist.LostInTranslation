<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindRootNodeAggregatesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
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
 * Walks the entire source-dimension subgraph from each root aggregate downward and emits
 * translation commands for every translatable node — independent of the stale-translation
 * projection. Where {@see WorkspaceSynchroniser} only acts on records the projection has
 * already flagged, this service treats every node in the source dimension as a candidate.
 *
 * Decision matrix per node (only applied if `directive->enabled === true` and the node is
 * non-tethered):
 *   - target variant absent → `CreateNodeVariant`; the {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
 *     cascades a full-properties `SetNodeProperties` automatically.
 *   - target variant present + `$skipExisting` + no stale row → skip (assume already translated
 *     or manually authored).
 *   - target variant present (other) → translated `SetNodeProperties` for *every* translatable
 *     property (not just the stale slice), built by {@see StalePropertyCommandBuilder}.
 *
 * Commands are dispatched directly (not returned) and tagged via
 * {@see AISystemTranslationRuntimeState} so events are attributed to the AI service.
 */
class FullWorkspaceSynchroniser
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

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    public function synchroniseWorkspaceFull(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $skipExisting = true,
        bool $useCache = true,
        bool $dryRun = false,
    ): WorkspaceSynchronisationResult {
        if (!$sourceWorkspaceName->equals($targetWorkspaceName)) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'cross-workspace full synchronisation is not yet supported (source "%s" != target "%s")',
                $sourceWorkspaceName->value,
                $targetWorkspaceName->value,
            ));
        }

        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
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
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'no referenceLanguage configured for target dimension %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }
        if (!$expectedSourceDsp->equals($sourceDimensionSpacePoint)) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
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
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);

        // Pre-fetch stale records for the (targetWorkspace, targetDSP) slice. Used only for the
        // `skipExisting` decision; full sync does not need them as a source of truth.
        $staleByNodeId = [];
        foreach ($cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder->findAll() as $stale) {
            assert($stale instanceof StaleTranslation);
            if (!$stale->workspaceName->equals($targetWorkspaceName)) {
                continue;
            }
            if ($stale->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            $staleByNodeId[$stale->nodeAggregateId->value] = $stale;
        }

        $contentGraph = $cr->getContentGraph($targetWorkspaceName);
        $sourceSubgraph = $contentGraph->getSubgraph(
            $sourceDimensionSpacePoint,
            NeosVisibilityConstraints::excludeRemoved(),
        );
        $targetSubgraph = $contentGraph->getSubgraph(
            $targetDimensionSpacePoint,
            NeosVisibilityConstraints::excludeRemoved(),
        );

        $perNodeResults = [];

        // DFS over every root aggregate's source-side subgraph. We iterate by following
        // findChildNodes — covers Documents, ContentCollection, Content alike, since the
        // legacy translateCommand pattern uses an unfiltered findChildNodes call.
        $rootAggregates = $contentGraph->findRootNodeAggregates(FindRootNodeAggregatesFilter::create());
        foreach ($rootAggregates as $rootAggregate) {
            $rootNode = $sourceSubgraph->findNodeById($rootAggregate->nodeAggregateId);
            if ($rootNode === null) {
                continue;
            }
            $this->walkNode(
                cr: $cr,
                node: $rootNode,
                sourceSubgraph: $sourceSubgraph,
                targetSubgraph: $targetSubgraph,
                targetOrigin: $targetOrigin,
                staleByNodeId: $staleByNodeId,
                sourceDeepl: $sourceDeepl,
                targetDeepl: $targetDeepl,
                skipExisting: $skipExisting,
                useCache: $useCache,
                dryRun: $dryRun,
                perNodeResults: $perNodeResults,
            );
        }

        return new WorkspaceSynchronisationResult($perNodeResults);
    }

    /**
     * @param array<string, StaleTranslation> $staleByNodeId
     * @param list<PerNodeSynchronisationResult> $perNodeResults
     */
    private function walkNode(
        ContentRepository $cr,
        Node $node,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
        OriginDimensionSpacePoint $targetOrigin,
        array $staleByNodeId,
        string $sourceDeepl,
        string $targetDeepl,
        bool $skipExisting,
        bool $useCache,
        bool $dryRun,
        array &$perNodeResults,
    ): void {
        $nodeTypeManager = $cr->getNodeTypeManager();
        $nodeType = $nodeTypeManager->getNodeType($node->nodeTypeName);
        if ($nodeType !== null && !$node->classification->isTethered()) {
            $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
            if ($directive->enabled) {
                $existsInTarget = $targetSubgraph->findNodeById($node->aggregateId) !== null;
                if (!$existsInTarget) {
                    $command = CreateNodeVariant::create(
                        $node->workspaceName,
                        $node->aggregateId,
                        $node->originDimensionSpacePoint,
                        $targetOrigin,
                    );
                    $perNodeResults[] = new PerNodeSynchronisationResult(
                        $node->aggregateId,
                        $dryRun
                            ? RetranslationResult::skipped('dry-run')
                            : new RetranslationResult(stalePropertyCommandsDispatched: 0, variantCommandsDispatched: 1),
                    );
                    if (!$dryRun) {
                        $this->dispatchAsAi($cr, $command);
                    }
                } elseif ($skipExisting && !isset($staleByNodeId[$node->aggregateId->value])) {
                    $perNodeResults[] = new PerNodeSynchronisationResult(
                        $node->aggregateId,
                        RetranslationResult::skipped('target variant exists and no stale rows (skipExisting)'),
                    );
                } else {
                    // Build SetNodeProperties for ALL translatable properties (full re-translate),
                    // not just the stale slice — that's the semantic difference from stale-driven sync.
                    $allTranslatableNames = PropertyNames::fromArray(array_map(
                        static fn ($t): PropertyName => $t->propertyName,
                        iterator_to_array($directive->translatablePropertyNames),
                    ));
                    $command = $this->stalePropertyCommandBuilder->buildSetNodeProperties(
                        nodeTypeManager: $nodeTypeManager,
                        sourceNode: $node,
                        stalePropertyNames: $allTranslatableNames,
                        targetOrigin: $targetOrigin,
                        sourceDeeplLanguage: $sourceDeepl,
                        targetDeeplLanguage: $targetDeepl,
                        useCache: $useCache,
                    );
                    if ($command === null) {
                        $perNodeResults[] = new PerNodeSynchronisationResult(
                            $node->aggregateId,
                            RetranslationResult::skipped('no translatable property values'),
                        );
                    } else {
                        $perNodeResults[] = new PerNodeSynchronisationResult(
                            $node->aggregateId,
                            $dryRun
                                ? RetranslationResult::skipped('dry-run')
                                : new RetranslationResult(stalePropertyCommandsDispatched: 1, variantCommandsDispatched: 0),
                        );
                        if (!$dryRun) {
                            $this->dispatchAsAi($cr, $command);
                        }
                    }
                }
            }
        }

        // Recurse: walking the *source* subgraph so we don't depend on the target tree shape.
        foreach ($sourceSubgraph->findChildNodes($node->aggregateId, FindChildNodesFilter::create()) as $childNode) {
            $this->walkNode(
                cr: $cr,
                node: $childNode,
                sourceSubgraph: $sourceSubgraph,
                targetSubgraph: $targetSubgraph,
                targetOrigin: $targetOrigin,
                staleByNodeId: $staleByNodeId,
                sourceDeepl: $sourceDeepl,
                targetDeepl: $targetDeepl,
                skipExisting: $skipExisting,
                useCache: $useCache,
                dryRun: $dryRun,
                perNodeResults: $perNodeResults,
            );
        }
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
