<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeNames;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\NodeType\NodeTypeCriteria;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

/**
 * Driver that brings a target-language dimension subtree back in sync with its source (reference)
 * language, by emitting:
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
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation')]
    protected bool $experimentalApplyHtmlEntityDecodeAfterTranslation = false;

    /**
     * Retranslate the subtree below `$nodeAggregateId` into `$targetDimensionSpacePoint`.
     *
     * The source DSP is derived from the target via the `referenceLanguage` config on the target
     * preset — callers do not pass it. Calling with the source language itself (no `referenceLanguage`)
     * is a legitimate no-op, returning `RetranslationResult::skipped(...)` rather than throwing.
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
    ): RetranslationResult {
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

        // ReferenceDimensionSpacePointResolver is #[Flow\Proxy(false)] and per-CR, so it's
        // constructed inline — matches StaleTranslationProjectionFactory's construction.
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
        // A null DeepL id means the preset explicitly disables translation (`deeplLanguage: false`).
        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            return RetranslationResult::skipped(sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $contentGraph = $cr->getContentGraph($workspaceName);
        $sourceSubgraph = $contentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $contentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        // Scope to the current document: nested Documents are separate translation scopes and would
        // get retranslated by their own call. `NodeTypeCriteria` deny rules are sub-type-aware, so
        // denying `Neos.Neos:Document` also denies its subtypes. The entry node itself is always
        // returned by `findSubtree`, so a Document entry still gets its own properties retranslated.
        $sourceSubtree = $sourceSubgraph->findSubtree(
            $nodeAggregateId,
            FindSubtreeFilter::create(
                nodeTypes: NodeTypeCriteria::createWithDisallowedNodeTypeNames(
                    NodeTypeNames::fromStringArray(['Neos.Neos:Document']),
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
        $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
            ->staleTranslationFinder
            ->findBySubtree($sourceSubtree, $targetOrigin);
        $staleByNodeAggregateId = [];
        foreach ($staleTranslations as $staleTranslation) {
            $staleByNodeAggregateId[$staleTranslation->nodeAggregateId->value] = $staleTranslation;
        }

        // One depth-first walk emits both buckets.
        // TODO: maybe without passing references to command arrays?
        $stalePropertyCommands = [];
        $variantCommands = [];
        $this->collectCommands(
            subtree: $sourceSubtree,
            targetSubgraph: $targetSubgraph,
            staleByNodeAggregateId: $staleByNodeAggregateId,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
            nodeTypeManager: $cr->getNodeTypeManager(),
            stalePropertyCommands: $stalePropertyCommands,
            variantCommands: $variantCommands,
        );

        // Dispatch order:
        //   * Stale fix-ups first (cheap, one DeepL call each). A mid-flight failure leaves variants
        //     missing rather than half-created with wrong content.
        //   * Variants second (each can fan out into multiple hook-cascade events).
        //
        // AI attribution: wrap direct `SetNodeProperties` dispatches via `dispatchAsAi` — the hook
        // does not fire for these. Variants are NOT wrapped; the hook's `onAfterHandle` sets the AI
        // id itself for its cascaded SetNodeProperties.
        //
        // Partial failure: `$cr->handle()` is sync and may throw; commands already dispatched stay
        // committed. This driver is best-effort.
        foreach ($stalePropertyCommands as $command) {
            $this->dispatchAsAi($cr, $command);
        }
        foreach ($variantCommands as $command) {
            $cr->handle($command);
        }

        return new RetranslationResult(
            stalePropertyCommandsDispatched: count($stalePropertyCommands),
            variantCommandsDispatched: count($variantCommands),
        );
    }

    /**
     * Depth-first pre-order walk of the source subtree. For each node:
     *
     *   - Emit `SetNodeProperties` if a stale record exists AND the target variant exists.
     *   - Emit `CreateNodeVariant` if the target variant is missing AND the node is non-tethered.
     *
     * Tethered nodes are skipped for variant creation — the CR auto-creates them with their
     * non-tethered ancestor, and the hook handles their property translation. We still recurse into
     * tethered subtrees in case they contain non-tethered descendants.
     *
     * The variant `sourceOrigin` is `$sourceNode->originDimensionSpacePoint` (where the node
     * actually lives), not the requested source DSP — the source may have fallen back via
     * generalisation, and the CR rejects mismatched origins.
     *
     * Workspace and target DSP are derived from the subgraphs / nodes rather than passed in.
     * `NodeTypeManager` is threaded through because it's per-CR (not a globally-injectable service).
     *
     * @param array<string, \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation> $staleByNodeAggregateId
     * @param list<SetNodeProperties> $stalePropertyCommands  Mutated; appended to in walk order.
     * @param list<CreateNodeVariant> $variantCommands        Mutated; appended to in walk order.
     */
    private function collectCommands(
        Subtree $subtree,
        ContentSubgraphInterface $targetSubgraph,
        array $staleByNodeAggregateId,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        NodeTypeManager $nodeTypeManager,
        array &$stalePropertyCommands,
        array &$variantCommands,
    ): void {
        $sourceNode = $subtree->node;
        $existsInTarget = $targetSubgraph->findNodeById($sourceNode->aggregateId) !== null;

        // Only emit SetNodeProperties when the target variant exists; otherwise the
        // CreateNodeVariant below + hook cascade handles initial translation.
        $stale = $staleByNodeAggregateId[$sourceNode->aggregateId->value] ?? null;
        if ($stale !== null && $existsInTarget) {
            $command = $this->tryBuildSetNodeProperties(
                nodeTypeManager: $nodeTypeManager,
                sourceNode: $sourceNode,
                stalePropertyNames: $stale->propertyNames,
                // Use the OriginDimensionSpacePoint from the stale record, not a freshly built one
                // — it reflects where the variant actually lives (matters for spec/gen variants).
                targetOrigin: $stale->originDimensionSpacePoint,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
            );
            if ($command !== null) {
                $stalePropertyCommands[] = $command;
            }
        }

        if (!$existsInTarget && !$sourceNode->classification->isTethered()) {
            $variantCommands[] = CreateNodeVariant::create(
                $sourceNode->workspaceName,
                $sourceNode->aggregateId,
                $sourceNode->originDimensionSpacePoint,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint()),
            );
        }

        foreach ($subtree->children as $childSubtree) {
            $this->collectCommands(
                subtree: $childSubtree,
                targetSubgraph: $targetSubgraph,
                staleByNodeAggregateId: $staleByNodeAggregateId,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
                nodeTypeManager: $nodeTypeManager,
                stalePropertyCommands: $stalePropertyCommands,
                variantCommands: $variantCommands,
            );
        }
    }

    /**
     * Build a translated `SetNodeProperties` for the explicit stale property list, or `null`.
     *
     * Slimmed clone of {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook::tryPrepareSetNodeProperties}.
     * The difference: the hook iterates ALL translatable properties (fresh variant, everything is
     * new); we iterate ONLY the explicit stale list, because editors may have manually overridden
     * other translated properties on the target side.
     *
     * Trusts the projection's invariant that stale records only exist for translation-enabled
     * node types and translatable properties — so guards on `directive->enabled` and `findByName`
     * are dropped. The `hasProperty` + empty-source guards remain: editors can blank source
     * properties between the projection write and our dispatch.
     */
    private function tryBuildSetNodeProperties(
        NodeTypeManager $nodeTypeManager,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
        OriginDimensionSpacePoint $targetOrigin,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $nodeType = $nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        // Defensive: projection guarantees the node type existed when the record was written.
        // If it's since been removed, we can't resolve the connector for non-string props.
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($stalePropertyNames as $propertyName) {
            if (!$sourceNode->hasProperty($propertyName)) {
                continue;
            }
            $sourceValue = $sourceNode->getProperty($propertyName);
            if ($sourceValue === null || (is_string($sourceValue) && trim($sourceValue) === '')) {
                continue;
            }

            $name = $propertyName->value;
            assert($name !== '');

            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if (is_object($sourceValue) && $translatable?->translationConnector !== null) {
                $propertiesToTranslate[$name] = $translatable->translationConnector->extractTranslations($sourceValue);
            } elseif (is_string($sourceValue)) {
                $propertiesToTranslate[$name] = $sourceValue;
            }
        }

        if ($propertiesToTranslate === []) {
            return null;
        }

        // deflate → translate → enflate so DeepL sees one string per leaf, connectors get reassembled.
        $deflated = ArrayFlatteningUtility::deflate($propertiesToTranslate);
        /** @var array<non-empty-string, string> $translatedDeflated */
        $translatedDeflated = $this->translationService->translate(
            $deflated,
            $targetDeeplLanguage,
            $sourceDeeplLanguage,
        );
        if ($this->experimentalApplyHtmlEntityDecodeAfterTranslation) {
            $translatedDeflated = array_map(
                static fn (string $value): string => html_entity_decode($value),
                $translatedDeflated,
            );
        }
        $translatedProperties = ArrayFlatteningUtility::enflate($translatedDeflated);

        $propertiesToSet = [];
        foreach ($translatedProperties as $name => $translatedValue) {
            // uriPathSegment has strict charset; DeepL routinely violates it.
            if (
                $name === 'uriPathSegment'
                && is_string($translatedValue)
                && !preg_match('/^[a-z0-9\-]+$/i', $translatedValue)
            ) {
                $translatedValue = $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
            }
            $targetValue = null;
            if (is_array($translatedValue)) {
                $translatable = $directive->translatablePropertyNames->findByName($name);
                $connector = $translatable?->translationConnector;
                if ($connector !== null) {
                    $sourceValue = $sourceNode->getProperty($name);
                    if (is_object($sourceValue)) {
                        $targetValue = $connector->applyTranslations($sourceValue, $translatedValue);
                    }
                }
            } else {
                $targetValue = $translatedValue;
            }
            if ($targetValue !== null) {
                $propertiesToSet[$name] = $targetValue;
            }
        }

        if ($propertiesToSet === []) {
            return null;
        }

        return SetNodeProperties::create(
            workspaceName: $sourceNode->workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
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
