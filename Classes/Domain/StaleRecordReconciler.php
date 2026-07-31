<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationMaintenance;

/**
 * Prunes stale-translation rows that no dispatched command will ever clear, so they do not linger and re-no-op on
 * every synchronization run.
 *
 * Shared by the three synchronization drivers ({@see Retranslator}, {@see FullWorkspaceSynchronizer} and the
 * publish-driven {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook}).
 */
final class StaleRecordReconciler
{
    /**
     * MUST be called only for a node the current run produced no translated `SetNodeProperties` for — otherwise that
     * command's `NodePropertiesWereSet` will clear the row (possibly only partially), and pruning here would discard
     * the still-stale remainder. Under that precondition the row is unsatisfiable, and is pruned, when either:
     *
     *  (a) the target variant already exists — nothing translatable could be set, so no `NodePropertiesWereSet` will
     *      ever fire to clear the row; or
     *  (b) the node is tethered with no flagged properties and its target variant is absent while its parent already
     *      exists in the target — the variant only materialises via a non-tethered ancestor's `CreateNodeVariant`
     *      cascade (which will not happen, the ancestor already exists) and there is nothing to set.
     *
     * A target-absent, non-tethered node is left alone: its `CreateNodeVariant` cascade will create and translate it.
     *
     * The row is always pruned in the TARGET workspace being reconciled — never the workspace the stale record was read
     * from, which for a cross-workspace run is the source (pruning there would wrongly clear the source's own pending
     * translation this run never touched). In the single-workspace case source and target coincide.
     */
    public static function pruneIfUnsatisfiable(
        StaleTranslationMaintenance $staleTranslationMaintenance,
        WorkspaceName $targetWorkspaceName,
        StaleTranslation $stale,
        Node $sourceNode,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
    ): void {
        $targetVariantExists = $targetSubgraph->findNodeById($sourceNode->aggregateId) !== null;
        if (!$targetVariantExists && !self::isTetheredStructuralNoOp($sourceNode, $stale, $sourceSubgraph, $targetSubgraph)) {
            return;
        }
        $staleTranslationMaintenance->removeStaleRow(
            $targetWorkspaceName,
            $stale->nodeAggregateId,
            $stale->originDimensionSpacePoint,
        );
    }

    private static function isTetheredStructuralNoOp(
        Node $sourceNode,
        StaleTranslation $stale,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
    ): bool {
        if (!$sourceNode->classification->isTethered() || !$stale->propertyNames->isEmpty()) {
            return false;
        }
        $parentNode = $sourceSubgraph->findParentNode($sourceNode->aggregateId);
        return $parentNode !== null && $targetSubgraph->findNodeById($parentNode->aggregateId) !== null;
    }
}
