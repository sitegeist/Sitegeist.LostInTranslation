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
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
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
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
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

        // `findSubtree` with an empty filter walks the entire subtree below `$nodeAggregateId`. We use
        // it (rather than recursive `findChildNodes`) so the same `Subtree` object can be fed to
        // `StaleTranslationFinder::findBySubtree` later when querying for stale records.
        $sourceSubtree = $sourceSubgraph->findSubtree($nodeAggregateId, FindSubtreeFilter::create());
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
        // We collect ALL commands before dispatching ANY. Rationale:
        //   * The stale-translation projection (and the content graph) only updates after each
        //     `$cr->handle()` returns. If we dispatched stale-fix commands during collection of
        //     variant commands, the variant walk would observe a partially-updated graph.
        //   * Collecting first also makes the order of dispatch (stale → variant) explicit and easy
        //     to reason about, regardless of how many of each we end up with.
        // -----------------------------------------------------------------------------------------

        $stalePropertyCommands = $this->collectStalePropertyCommands(
            cr: $cr,
            workspaceName: $workspaceName,
            nodeAggregateId: $nodeAggregateId,
            sourceSubgraph: $sourceSubgraph,
            targetSubgraph: $targetSubgraph,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
        );

        $variantCommands = $this->collectMissingVariantCommands(
            subtree: $sourceSubtree,
            targetSubgraph: $targetSubgraph,
            workspaceName: $workspaceName,
            targetDimensionSpacePoint: $targetDimensionSpacePoint,
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
     * Build one `SetNodeProperties` command per stale-translation record under the target subtree.
     *
     * Key design decision — feed the **target** subtree (not the source) into
     * `StaleTranslationFinder::findBySubtree`. The finder filters by
     * `subtree.node.originDimensionSpacePoint.hash`, which on the target side is the target DSP. The
     * source subtree's origin would obviously not match any target-language stale records.
     *
     * If the target root node does not exist yet (typical first retranslation: the user creates a
     * page in `en`, never translates it to `de`, then asks us to retranslate `de`), this branch
     * short-circuits. The companion {@see collectMissingVariantCommands()} pass will create the
     * variant; the {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
     * then cascades the translation, so no work is missed.
     *
     * @return list<SetNodeProperties>
     */
    private function collectStalePropertyCommands(
        ContentRepository $cr,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): array {
        // No target node yet → nothing exists to fix-up; defer the whole subtree to the
        // missing-variant pass + hook cascade.
        if ($targetSubgraph->findNodeById($nodeAggregateId) === null) {
            return [];
        }
        $targetSubtree = $targetSubgraph->findSubtree($nodeAggregateId, FindSubtreeFilter::create());
        if ($targetSubtree === null) {
            return [];
        }

        // Projection state is accessed by interface class name. This returns the
        // `StaleTranslationFinder` instance built per-CR by the projection factory.
        $finder = $cr->projectionState(StaleTranslationFinder::class);
        $staleTranslations = $finder->findBySubtree($targetSubtree);

        $commands = [];
        foreach ($staleTranslations as $staleTranslation) {
            $sourceNode = $sourceSubgraph->findNodeById($staleTranslation->nodeAggregateId);
            if ($sourceNode === null) {
                // The stale record references a node that no longer exists in the source dimension —
                // typically because the source node was removed after the stale record was written.
                // Skipping is safe: a subsequent `NodeAggregateWasRemoved` projection handler (TODO
                // in StaleTranslationProjection) is the proper place to clean this up.
                $this->logger?->debug(sprintf(
                    'Retranslator: stale node %s missing in source DSP; skipping.',
                    $staleTranslation->nodeAggregateId->value,
                ));
                continue;
            }
            $command = $this->tryBuildSetNodeProperties(
                cr: $cr,
                sourceNode: $sourceNode,
                stalePropertyNames: $staleTranslation->propertyNames,
                workspaceName: $workspaceName,
                // Use the OriginDimensionSpacePoint from the stale record, not a freshly built one
                // from `$targetDimensionSpacePoint`. The stale record's origin already reflects where
                // the target variant actually lives in this workspace — important when the variant
                // was created by specialisation/generalisation and may not match the requested target
                // DSP exactly.
                targetOrigin: $staleTranslation->originDimensionSpacePoint,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
            );
            if ($command !== null) {
                $commands[] = $command;
            }
        }
        return $commands;
    }

    /**
     * Walk the source subtree and emit a `CreateNodeVariant` command for every node not yet present
     * in the target subgraph.
     *
     * Crucially, we use `$sourceNode->originDimensionSpacePoint` as the `sourceOrigin` of the variant
     * command — NOT a freshly built OriginDSP from `$targetDimensionSpacePoint`'s sibling-source. The
     * source node may have originated in a generalisation/specialisation of the requested source DSP
     * (i.e. fall back behaviour), and the CR rejects `CreateNodeVariant` commands whose `sourceOrigin`
     * doesn't match where the node actually lives.
     *
     * We do NOT emit a follow-up `SetNodeProperties` here for the freshly-created variant — the
     * existing {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook}
     * intercepts `CreateNodeVariant` events and emits the translated SetNodeProperties cascade
     * itself (including for tethered descendants). Duplicating that work here would cause double
     * translation and conflicting events.
     *
     * @return list<CreateNodeVariant>
     */
    private function collectMissingVariantCommands(
        Subtree $subtree,
        ContentSubgraphInterface $targetSubgraph,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): array {
        $commands = [];
        $sourceNode = $subtree->node;
        if ($targetSubgraph->findNodeById($sourceNode->aggregateId) === null) {
            $commands[] = CreateNodeVariant::create(
                $workspaceName,
                $sourceNode->aggregateId,
                $sourceNode->originDimensionSpacePoint,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
            );
        }
        // Recursive walk — `Subtree::$children` is a `Subtrees` IteratorAggregate of further `Subtree`
        // instances, so the recursion bottoms out naturally at leaf nodes (empty children).
        foreach ($subtree->children as $childSubtree) {
            foreach (
                $this->collectMissingVariantCommands(
                    $childSubtree,
                    $targetSubgraph,
                    $workspaceName,
                    $targetDimensionSpacePoint,
                ) as $childCommand
            ) {
                $commands[] = $childCommand;
            }
        }
        return $commands;
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
        if ($nodeType === null) {
            // Node type was removed from the schema since the stale record was written.
            // Without a node type we cannot determine which properties are translatable or which
            // connector to use, so we cannot safely build a command.
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        if ($directive->enabled === false) {
            // Node type opted out of automatic translation after the stale record was written.
            return null;
        }

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($stalePropertyNames as $propertyName) {
            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if ($translatable === null) {
                // Property is no longer translatable per the current node type definition — skip.
                // This is an edge case (integrator removed `automaticTranslation: true` from a
                // property).
                continue;
            }
            if (!$sourceNode->hasProperty($propertyName)) {
                continue;
            }
            $sourceValue = $sourceNode->getProperty($propertyName);
            if ($sourceValue === null || (is_string($sourceValue) && trim($sourceValue) === '')) {
                // Empty / whitespace-only values produce useless DeepL calls.
                continue;
            }

            // Property does not exist anymore?
            $name = $propertyName->value;
            assert($name !== '');

            if (is_object($sourceValue) && ($connector = $translatable->translationConnector) !== null) {
                // Non-string property type → use its registered TranslationConnector to extract the
                // translatable string fragments (returns an associative array<string, string>). These
                // get enflated/deflated through `ArrayFlatteningUtility` below so DeepL sees a flat
                // string→string map.
                $propertiesToTranslate[$name] = $connector->extractTranslations($sourceValue);
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
