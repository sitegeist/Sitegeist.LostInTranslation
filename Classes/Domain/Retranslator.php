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
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

/**
 * Driver that brings a target-language dimension subtree back in sync with its configured source
 * (reference) language.
 *
 * Two complementary out-of-sync situations are repaired:
 *
 * 1. **Stale property translations** — variants that already exist in the target dimension but whose
 *    translated properties have drifted because the source-language node was edited. The
 *    {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}
 *    records these and is the authoritative source for "what is stale".
 *
 * 2. **Missing variants** — nodes that exist in the source dimension but have no variant in the target
 *    dimension yet. Creating the variant is enough to trigger the existing
 *    {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} which
 *    will cascade translation automatically (including tethered children).
 *
 * Both fix-up paths are produced as commands first, then dispatched. Stale-property commands are
 * dispatched while {@see AISystemTranslationRuntimeState} marks the AI as the acting user, so the
 * resulting event metadata is attributed to the AI service rather than the editor that invoked
 * retranslation.
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

    protected ?LoggerInterface $logger = null;

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation')]
    protected bool $experimentalApplyHtmlEntityDecodeAfterTranslation = false;

    /**
     * Retranslate (stale-property fix-ups + missing-variant creates) the subtree below
     * `$nodeAggregateId` into `$targetDimensionSpacePoint`.
     *
     * The source DSP is **derived** from the target via the `referenceLanguage` configuration on the
     * target dimension preset — callers do not pass it. If the target preset has no `referenceLanguage`
     * configured (e.g. the source language itself), this method becomes a no-op.
     *
     * Best-effort semantics: every misconfiguration (no reference language, no DeepL mapping, missing
     * source node, etc.) results in a `RetranslationResult::skipped(...)` return rather than an
     * exception. Callers can distinguish a real dispatch from a no-op via the returned counts /
     * {@see RetranslationResult::isNoOp()} — this was the previous void return's blind spot.
     *
     * Repeated invocations are idempotent: existing variants are skipped, and stale records are
     * consumed by the projection as soon as the projection sees the resulting `NodePropertiesWereSet`
     * events.
     */
    public function retranslateNode(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): RetranslationResult {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);

        // Guard against misconfiguration: the configured `languageDimensionName` must correspond to an
        // actual ContentDimension in this CR. Without this guard, `ReferenceDimensionSpacePointResolver`
        // would silently behave as if there were no reference language.
        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            $reason = sprintf(
                'language dimension "%s" not configured in CR "%s"',
                $this->languageDimensionName,
                $contentRepositoryId->value,
            );
            $this->logger?->debug('Retranslator: ' . $reason . '; skipping.');
            return RetranslationResult::skipped($reason);
        }

        // `ReferenceDimensionSpacePointResolver` is marked `#[Flow\Proxy(false)]` and therefore cannot
        // be Flow-injected. It also needs per-CR data (variation graph + dimension source), so it is
        // constructed inline here — the same construction used by `StaleTranslationProjectionFactory`.
        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );

        // The whole retranslation flow is driven from the target DSP — the source is *derived* from
        // the target's `referenceLanguage` preset option. Calling retranslate on the source language
        // itself (e.g. `en` when only `de` has `referenceLanguage: en`) is a legitimate no-op rather
        // than an error: any caller iterating over all dimension values would otherwise hit a noisy
        // exception on the source dimension iteration.
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($sourceDimensionSpacePoint === null) {
            $reason = sprintf(
                'no referenceLanguage configured for target DSP %s',
                $targetDimensionSpacePoint->toJson(),
            );
            $this->logger?->debug('Retranslator: ' . $reason . '; skipping.');
            return RetranslationResult::skipped($reason);
        }

        // `DimensionValueDirectiveFactory` is stateless — instantiating inline avoids polluting the
        // injection footprint. Matches the construction used by `TranslationCommandHookFactory`.
        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDirective = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        );
        $targetDirective = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        );
        $sourceDeeplLanguage = $sourceDirective?->deeplSourceId;
        $targetDeeplLanguage = $targetDirective?->deeplTargetId;

        // A null DeepL id on either side means the preset explicitly disables translation
        // (`deeplLanguage: false`) — short-circuit instead of pushing the editor's content through
        // DeepL with a wrong/missing language code.
        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            $reason = sprintf(
                'DeepL language not resolvable for source %s or target %s',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            );
            $this->logger?->debug('Retranslator: ' . $reason . '; skipping.');
            return RetranslationResult::skipped($reason);
        }

        $contentGraph = $cr->getContentGraph($workspaceName);

        // `excludeRemoved` (not `withoutRestrictions`) is intentional: we want to see disabled nodes
        // — editors may have disabled them in the source — but not removed ones, since copying a
        // removed node into the target dimension would resurrect it. Matches the existing
        // `TranslationCommandHook` behaviour for retranslation cascades.
        $sourceSubgraph = $contentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $contentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        // Walk the entry node's subtree but stop at nested Documents. Retranslating one document
        // should not bleed into its child pages — each child Document is its own translation scope
        // and would otherwise be picked up by a separate retranslate call. The
        // `NodeTypeCriteria::createWithDisallowedNodeTypeNames` contract is sub-type-aware (see
        // `Packages/Libraries/neos/contentrepository-core/.../NodeTypeCriteria.php`): denying
        // `Neos.Neos:Document` also denies every NodeType inheriting from it.
        //
        // The entry node itself is always returned by `findSubtree` even if it is a Document —
        // only descendants are filtered. That's the intended behaviour: the entry Document's own
        // properties (title, uriPathSegment, …) still get retranslated.
        $sourceSubtree = $sourceSubgraph->findSubtree(
            $nodeAggregateId,
            FindSubtreeFilter::create(
                nodeTypes: NodeTypeCriteria::createWithDisallowedNodeTypeNames(
                    NodeTypeNames::fromStringArray(['Neos.Neos:Document']),
                ),
            ),
        );
        if ($sourceSubtree === null) {
            $reason = sprintf(
                'source node %s not found in DSP %s',
                $nodeAggregateId->value,
                $sourceDimensionSpacePoint->toJson(),
            );
            $this->logger?->debug('Retranslator: ' . $reason . '; skipping.');
            return RetranslationResult::skipped($reason);
        }

        // -----------------------------------------------------------------------------------------
        // Command collection phase
        //
        // We collect ALL commands before dispatching ANY, in one depth-first walk of the source
        // subtree. Rationale:
        //   * The stale-translation projection (and the content graph) only updates after each
        //     `$cr->handle()` returns. Collecting up-front guarantees we never observe a
        //     partially-updated state.
        //   * One walk emits both buckets, so they end up in matching subtree order. The dispatch
        //     loop preserves that order, which keeps the resulting event stream hierarchical —
        //     important for the Behat event-index assertions and easier to reason about overall.
        // -----------------------------------------------------------------------------------------

        // Pre-fetch all stale records under the source subtree at the target origin, keyed by
        // node aggregate id value for O(1) lookup during the walk. The finder takes the
        // *source* subtree (for the node id list it covers) but filters by the *target* origin
        // dsp hash — that's where stale records live.
        $staleByNodeAggregateId = [];
        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $staleTranslations = $cr->projectionState(StaleTranslationReadModel::class)
            ->staleTranslationFinder
            ->findBySubtree($sourceSubtree, $targetOrigin);
        foreach ($staleTranslations as $staleTranslation) {
            $staleByNodeAggregateId[$staleTranslation->nodeAggregateId->value] = $staleTranslation;
        }

        $stalePropertyCommands = [];
        $variantCommands = [];
        $this->collectCommands(
            subtree: $sourceSubtree,
            targetSubgraph: $targetSubgraph,
            staleByNodeAggregateId: $staleByNodeAggregateId,
            workspaceName: $workspaceName,
            targetDimensionSpacePoint: $targetDimensionSpacePoint,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
            cr: $cr,
            stalePropertyCommands: $stalePropertyCommands,
            variantCommands: $variantCommands,
        );

        // -----------------------------------------------------------------------------------------
        // Dispatch phase — stale fix-ups FIRST, then variant creates.
        //
        // Order rationale:
        //   * Stale fix-ups are cheap and bounded by the projection (one DeepL call per stale row).
        //     Dispatching them first means a mid-flight failure (e.g. DeepL outage) leaves the
        //     smaller, simpler work either done or untouched — variants stay missing rather than
        //     half-created with wrong content.
        //   * Variant creates can each fan out into multiple cascaded SetNodeProperties via the
        //     `TranslationCommandHook` (one for the variant itself plus one per tethered descendant),
        //     so they are the heavier operation.
        //
        // AI attribution wrapping:
        //   * Stale SetNodeProperties go through `dispatchAsAi` to ensure the AuthProvider reports the
        //     AI service as the actor. The `TranslationCommandHook` does NOT fire for these (it only
        //     intercepts `CreateNodeVariant`), so without explicit wrapping the events would be
        //     attributed to whoever invoked retranslation (CLI user / editor).
        //   * `CreateNodeVariant` dispatches are NOT wrapped here — the hook's own `onAfterHandle`
        //     sets the AI service ID before emitting its cascade SetNodeProperties, so its events
        //     are correctly attributed by that path. Wrapping them here would only matter for the
        //     `CreateNodeVariant` event itself, which represents an editorial intent (the variant
        //     should exist) rather than an AI translation.
        //
        // Partial failure semantics:
        //   * `$cr->handle()` is synchronous and may throw. If it does, commands already dispatched
        //     stay committed (the event store has no rollback across separate `handle()` calls), and
        //     remaining commands do not run. Callers that need transactional semantics across the
        //     whole retranslation must coordinate at a higher level — this driver is best-effort.
        // -----------------------------------------------------------------------------------------
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
     * Depth-first pre-order walk of the source subtree. For each visited source node:
     *
     *   * If a stale record exists for it at the target origin, build a translated
     *     `SetNodeProperties` and append to `$stalePropertyCommands`.
     *   * If it has no variant in the target subgraph AND it is non-tethered, build a
     *     `CreateNodeVariant` and append to `$variantCommands`.
     *
     * Tethered nodes are skipped for variant creation: the CR auto-creates structurally-required
     * tethered descendants as part of their non-tethered parent's variant creation, and the
     * {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
     * cascades property translation for those auto-created tethered nodes. Emitting an explicit
     * `CreateNodeVariant` for a tethered node would be redundant or rejected. We still recurse into
     * tethered subtrees, because their descendants may include non-tethered nodes that need their
     * own command.
     *
     * Variant `sourceOrigin` uses `$sourceNode->originDimensionSpacePoint` (where the source node
     * actually lives), not a freshly built OriginDSP from `$targetDimensionSpacePoint`. The CR
     * rejects `CreateNodeVariant` commands whose `sourceOrigin` doesn't match where the node
     * actually lives — important when the source has fallen back via generalisation.
     *
     * @param array<string, \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation> $staleByNodeAggregateId
     * @param list<SetNodeProperties> $stalePropertyCommands  Mutated in place; appended to in walk order.
     * @param list<CreateNodeVariant> $variantCommands        Mutated in place; appended to in walk order.
     */
    private function collectCommands(
        Subtree $subtree,
        ContentSubgraphInterface $targetSubgraph,
        array $staleByNodeAggregateId,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        ContentRepository $cr,
        array &$stalePropertyCommands,
        array &$variantCommands,
    ): void {
        $sourceNode = $subtree->node;
        $existsInTarget = $targetSubgraph->findNodeById($sourceNode->aggregateId) !== null;

        // Only emit SetNodeProperties when the target variant already exists. If it doesn't, we
        // fall through to CreateNodeVariant below; the hook's translation cascade then writes the
        // initial translated properties and clears the matching stale record via the projection's
        // NodePropertiesWereSet handler. Emitting SetNodeProperties against a non-existent target
        // origin would be rejected by the CR.
        $stale = $staleByNodeAggregateId[$sourceNode->aggregateId->value] ?? null;
        if ($stale !== null && $existsInTarget) {
            $command = $this->tryBuildSetNodeProperties(
                cr: $cr,
                sourceNode: $sourceNode,
                stalePropertyNames: $stale->propertyNames,
                workspaceName: $workspaceName,
                // The stale record's origin already reflects where the target variant actually
                // lives in this workspace — important when the variant was created by
                // specialisation/generalisation and may not match the requested target DSP exactly.
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
                $workspaceName,
                $sourceNode->aggregateId,
                $sourceNode->originDimensionSpacePoint,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
            );
        }

        foreach ($subtree->children as $childSubtree) {
            $this->collectCommands(
                subtree: $childSubtree,
                targetSubgraph: $targetSubgraph,
                staleByNodeAggregateId: $staleByNodeAggregateId,
                workspaceName: $workspaceName,
                targetDimensionSpacePoint: $targetDimensionSpacePoint,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
                cr: $cr,
                stalePropertyCommands: $stalePropertyCommands,
                variantCommands: $variantCommands,
            );
        }
    }

    /**
     * Build a translated `SetNodeProperties` command for the specific properties named in a stale
     * record, or return null if there is nothing to translate.
     *
     * This is a deliberately **slimmed clone** of
     * {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook::tryPrepareSetNodeProperties}.
     * The two differ in one important way:
     *
     *  - The hook iterates ALL translatable properties on the node type (it has no notion of "which
     *    properties are stale" — it runs on a fresh variant creation, so everything is "new").
     *  - This method iterates ONLY the explicit `$stalePropertyNames` list. That matters because
     *    editors may have manually overridden translated properties on the target side; we must not
     *    silently overwrite those by re-translating everything. Only properties the projection has
     *    marked as drifted get touched.
     *
     * The remaining steps (deflate → DeepL `translate` → optional html_entity_decode → enflate →
     * connector apply → uriPathSegment sanitisation) mirror the hook exactly so that the
     * `SetNodeProperties` produced by retranslation is byte-equivalent to what the hook would emit
     * for the same source-language values. This was a conscious choice over extracting a shared
     * service: the hook's signature takes a `CreateNodeVariant` and our context has none, so reuse
     * would require either a synthetic command or a refactor of the hook — both of which were
     * judged worse than ~40 lines of duplication for now.
     */
    /**
     * Trust contract: `StaleTranslationProjection` only writes stale records for node aggregates
     * whose NodeType is translation-enabled AND whose listed properties are translatable at the
     * time of writing. So we no longer guard against `null` NodeType, disabled directive, or
     * `findByName === null` — if a record exists, those invariants held. The `StaleTranslationProjection`
     * is also expected to update its own rows when translation config changes (e.g. on replay), so
     * post-write drift is handled there, not here.
     *
     * The remaining per-property guards (`hasProperty` + empty-value) still matter: an editor can
     * blank out the source property between the stale record being written and this dispatch, and
     * we should silently skip rather than emit a no-op or wrong translation.
     */
    private function tryBuildSetNodeProperties(
        ContentRepository $cr,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
        WorkspaceName $workspaceName,
        OriginDimensionSpacePoint $targetOrigin,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $nodeType = $cr->getNodeTypeManager()->getNodeType($sourceNode->nodeTypeName);
        // Defensive: the projection guarantees the node type existed when the stale record was
        // written. If it has been removed from the schema since, there is no way to identify the
        // connector for non-string properties — skip rather than throw.
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
                // Empty / whitespace-only values produce useless DeepL calls.
                continue;
            }

            $name = $propertyName->value;
            assert($name !== '');

            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if (is_object($sourceValue) && $translatable?->translationConnector !== null) {
                // Non-string property type → use its registered TranslationConnector to extract the
                // translatable string fragments (returns an associative array<string, string>).
                // These get enflated/deflated through `ArrayFlatteningUtility` below so DeepL sees
                // a flat string→string map.
                $propertiesToTranslate[$name] = $translatable->translationConnector->extractTranslations($sourceValue);
            } elseif (is_string($sourceValue)) {
                $propertiesToTranslate[$name] = $sourceValue;
            }
        }

        if ($propertiesToTranslate === []) {
            return null;
        }

        // `ArrayFlatteningUtility::deflate` converts the (possibly nested via connector) value tree
        // into a flat dotted-key map so DeepL receives one string per leaf. `enflate` reverses it.
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
            // `uriPathSegment` has a strict character set (lowercase alphanumeric + hyphen). DeepL
            // routinely returns translations that violate this (capitalisation, spaces, accents), so
            // we route them through the URI path segment generator to produce a valid slug. Same
            // logic as the hook.
            if (
                $name === 'uriPathSegment'
                && is_string($translatedValue)
                && !preg_match('/^[a-z0-9\-]+$/i', $translatedValue)
            ) {
                $translatedValue = $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
            }
            $targetValue = null;
            if (is_array($translatedValue)) {
                // Non-string property — round-trip through its connector to reassemble the value
                // object from the translated fragments + the original source value (for any
                // non-translatable metadata the connector wants to copy).
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
            workspaceName: $workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }

    /**
     * Dispatch a command with AI authorship attribution active for the duration of the call.
     *
     * The `try`/`finally` is load-bearing: an exception inside `$cr->handle()` must still reset the
     * singleton {@see AISystemTranslationRuntimeState}, otherwise a later command in the same request
     * (e.g. an editor's save) would be incorrectly attributed to the AI. The hook itself resets state
     * at the top of every `onAfterHandle`, but its first reset only happens after the failed
     * command — too late.
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
