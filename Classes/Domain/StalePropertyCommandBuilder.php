<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirective;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

/**
 * Builds a translated `SetNodeProperties` command for the explicit stale property list of one node.
 *
 * Shared by:
 *  - {@see Retranslator} (subtree retranslation),
 *  - {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook} (auto-sync on
 *    workspace publish),
 *  - {@see FullWorkspaceSynchronizer} (full-workspace sync CLI).
 *
 * {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} (translate-on-variant-
 * creation) shares only the second half, via {@see self::buildFromCollectedProperties()} — it has no stale list to
 * work from and collects properties itself.
 *
 * Trusts the stale-translation projection's invariant: records only exist for translation-enabled node types and
 * translatable properties — so guards on `directive->enabled` and `findByName` are dropped. The `hasProperty` +
 * empty-source guards remain because editors may blank a source property between the projection write and our
 * dispatch.
 *
 * @internal Only for consumption inside Sitegeist.LostInTranslation.
 */
class StalePropertyCommandBuilder
{
    #[Flow\Inject]
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation')]
    protected bool $experimentalApplyHtmlEntityDecodeAfterTranslation = false;

    /**
     * The emitted command targets `$targetWorkspaceName` when given, else the workspace the source node was read from.
     * They differ only for cross-workspace synchronization, where source content is read from one workspace and the
     * translated `SetNodeProperties` is dispatched into another (e.g. read `live`, write `de-review`).
     */
    public function buildSetNodeProperties(
        NodeTypeManager $nodeTypeManager,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
        OriginDimensionSpacePoint $targetOrigin,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        ?WorkspaceName $targetWorkspaceName = null,
    ): ?SetNodeProperties {
        $targetWorkspaceName ??= $sourceNode->workspaceName;
        $nodeType = $nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        // Defensive: projection guarantees the node type existed when the record was written. If it's since been
        // removed, we can't resolve the connector for non-string props.
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);

        [$propertiesToTranslate, $propertiesToClear] = $this->collectPropertiesToWrite(
            $nodeType,
            $directive,
            $sourceNode,
            $stalePropertyNames,
        );

        return $this->buildFromCollectedProperties(
            directive: $directive,
            sourceNode: $sourceNode,
            propertiesToTranslate: $propertiesToTranslate,
            propertiesToSet: $propertiesToClear,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
            targetWorkspaceName: $targetWorkspaceName,
            targetOrigin: $targetOrigin,
        );
    }

    /**
     * Translate-and-write-back half of the build: turns already-collected source values into a `SetNodeProperties`.
     *
     * Exposed because the *collection* halves of the two translating drivers genuinely differ, while everything
     * downstream of "here are the values to translate" is identical. This class collects an explicit stale property
     * list; {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHook} collects every
     * translatable property of a node whose variant is being created, and keeps the `directive->enabled` guard this
     * class drops (it is driven by a command, not by the projection whose invariant that guard would duplicate).
     * Both then need one batched DeepL call, connectors reassembled, and per-property post-processors applied.
     *
     * @param array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate
     * @param array<non-empty-string, string> $propertiesToSet Values already decided without translating — blank
     *        sources propagated verbatim to mirror a clearing. Translated values are merged on top. Callers creating
     *        a target variant pass `[]`: there is no pre-existing translation to clear.
     */
    public function buildFromCollectedProperties(
        NodeTypeTranslationDirective $directive,
        Node $sourceNode,
        array $propertiesToTranslate,
        array $propertiesToSet,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
        WorkspaceName $targetWorkspaceName,
        OriginDimensionSpacePoint $targetOrigin,
    ): ?SetNodeProperties {
        $translatedProperties = [];

        if ($propertiesToTranslate !== []) {
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
        }

        foreach ($translatedProperties as $name => $translatedValue) {
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
                // Apply the optional per-property post-processor (e.g. coerce a translated uriPathSegment back into a
                // valid slug) to the scalar translated value.
                $postProcessor = $directive->translatablePropertyNames->findByName($name)?->postProcessor;
                if (is_string($translatedValue) && $postProcessor !== null) {
                    $translatedValue = $postProcessor->process($translatedValue);
                }
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
            workspaceName: $targetWorkspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }

    /**
     * Whether {@see self::buildSetNodeProperties()} would emit a command for this node — answered from the source
     * values alone, i.e. WITHOUT the DeepL round-trip that building performs. Exists so `--dry-run` can preview the
     * property updates a run would dispatch without paying for them: building the command *is* the translation, so a
     * preview that called `buildSetNodeProperties()` and discarded the result would cost exactly as much as the run it
     * is supposed to estimate.
     *
     * Both answers come out of the same {@see self::collectPropertiesToWrite()} step, so preview and real run agree on
     * which nodes carry work. The single case the preview cannot foresee: a translated object property whose connector
     * yields nothing to write back, where the real build returns null after translating. That is rare, and erring
     * towards "would write" costs nothing but an over-count of one in a report.
     */
    public function wouldBuildSetNodeProperties(
        NodeTypeManager $nodeTypeManager,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
    ): bool {
        $nodeType = $nodeTypeManager->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            return false;
        }
        [$propertiesToTranslate, $propertiesToClear] = $this->collectPropertiesToWrite(
            $nodeType,
            $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType),
            $sourceNode,
            $stalePropertyNames,
        );
        return $propertiesToTranslate !== [] || $propertiesToClear !== [];
    }

    /**
     * Source-side half of the build: which of `$stalePropertyNames` actually carry a value to write, split into the
     * ones that need translating and the blank ones propagated verbatim.
     *
     * @return array{array<non-empty-string, string|array<non-empty-string, string>>, array<non-empty-string, string>}
     */
    private function collectPropertiesToWrite(
        NodeType $nodeType,
        NodeTypeTranslationDirective $directive,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
    ): array {
        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        // String properties whose source is blank ("" / whitespace) are propagated verbatim to the target so the
        // clearing is mirrored — without round-tripping through DeepL (which would otherwise turn "" into
        // " translated" via the dummy service, and is a wasted call against the real DeepL API).
        /** @var array<non-empty-string, string> $propertiesToClear */
        $propertiesToClear = [];
        foreach ($stalePropertyNames as $propertyName) {
            if (!$nodeType->hasProperty($propertyName->value)) {
                continue;
            }
            $sourceValue = $sourceNode->getProperty($propertyName);
            if ($sourceValue === null) {
                continue;
            }

            $name = $propertyName->value;
            assert($name !== '');

            if (is_string($sourceValue) && trim($sourceValue) === '') {
                $propertiesToClear[$name] = $sourceValue;
                continue;
            }

            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if (is_object($sourceValue) && $translatable?->translationConnector !== null) {
                $propertiesToTranslate[$name] = $translatable->translationConnector->extractTranslations($sourceValue);
            } elseif (is_string($sourceValue)) {
                $propertiesToTranslate[$name] = $sourceValue;
            }
        }

        return [$propertiesToTranslate, $propertiesToClear];
    }
}
