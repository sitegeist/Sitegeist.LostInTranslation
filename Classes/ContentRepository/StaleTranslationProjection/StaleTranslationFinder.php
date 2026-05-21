<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Finder for stale translations
 *
 * @internal Only for consumption inside LostInTranslation.
 */
#[Flow\Proxy(false)]
final class StaleTranslationFinder implements ProjectionStateInterface
{
    public function __construct(
        private readonly Connection $dbal,
        private readonly string     $tableName,
    )
    {
    }

    public function findAll(): StaleTranslations
    {
        $staleTranslationRows = $this->dbal->executeQuery(
            <<<SQL
            SELECT * FROM {$this->tableName}
            SQL,
        )->fetchAllAssociative();

        return StaleTranslations::fromDatabaseRows($staleTranslationRows);
    }

    /**
     * Find all stale-translation records for nodes covered by the given **source-language** subtree,
     * in the subtree's workspace, at the **target-language** origin.
     *
     * Two-dimension lookup:
     *   - The subtree is read on the *source* side (because that's the tree the caller has at hand:
     *     it's the document content being retranslated).
     *   - The records being read live at the *target* origin (because stale records are written at
     *     the dimension where translations land).
     *
     * Both subgraphs share the same workspace, so `$subtree->node->workspaceName` is also the right
     * workspace for the target-side records.
     *
     * Results are returned in the order of {@see mapSubtreeToNodeAggregateIds()} — depth-first
     * pre-order of the source subtree. The SQL itself is unordered (a single `IN (...)` SELECT);
     * the ordering is reconstructed in PHP via `usort` against an aggregate-id → position map.
     * Hierarchical order matters at the call site: when the Retranslator dispatches
     * `SetNodeProperties` commands in this order, the resulting event-stream order also follows the
     * subtree, which keeps the Behat event-index assertions stable.
     */
    public function findBySubtree(Subtree $subtree, OriginDimensionSpacePoint $targetOriginSpacePoint): StaleTranslations
    {
        $orderedIds = $this->mapSubtreeToNodeAggregateIds($subtree);
        if ($orderedIds === []) {
            return new StaleTranslations();
        }

        $staleTranslationRows = $this->dbal->executeQuery(
            <<<SQL
            SELECT * FROM {$this->tableName}
                WHERE workspaceName = :workspaceName
                    AND nodeAggregateId IN (:nodeAggregateIds)
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash
            SQL,
            [
                'workspaceName' => $subtree->node->workspaceName->value,
                'nodeAggregateIds' => $orderedIds,
                'originDimensionSpacePointHash' => $targetOriginSpacePoint->hash,
            ],
            [
                // `ArrayParameterType::STRING` is REQUIRED for `IN (:placeholder)` expansion. Without
                // it, DBAL binds the value as a single parameter, the PDO driver coerces the array
                // to the literal string "Array" (with a PHP warning), and the IN clause matches
                // nothing. Discovered while wiring up the Retranslator: the finder was silently
                // returning empty results for every call.
                // TODO: Validate that assumption
                'nodeAggregateIds' => ArrayParameterType::STRING,
            ]
        )->fetchAllAssociative();

        // Reorder rows to match the depth-first walk order of the source subtree. The SQL above
        // makes no order guarantee — we'd otherwise get DB-page or PK order, which has no
        // correspondence to tree structure.
        $orderIndex = array_flip($orderedIds);
        usort(
            $staleTranslationRows,
            static fn(array $a, array $b): int => $orderIndex[$a['nodeAggregateId']] <=> $orderIndex[$b['nodeAggregateId']]
        );

        return StaleTranslations::fromDatabaseRows($staleTranslationRows);
    }

    /**
     * Flatten the subtree into the list of its node aggregate id values, in depth-first pre-order
     * (entry node first, then each child's subtree recursively).
     *
     * Returns a plain `list<string>` rather than `NodeAggregateIds` because the order matters here:
     * `NodeAggregateIds` is a set-style collection — its iteration order is not part of its contract,
     * and `merge()` does not promise to preserve insertion order across merges. Callers (and this
     * class's own ordering logic) rely on the depth-first sequence, so an ordered array is the right
     * shape.
     *
     * @return list<string>
     */
    private function mapSubtreeToNodeAggregateIds(Subtree $subtree): array
    {
        $result = [$subtree->node->aggregateId->value];
        foreach ($subtree->children as $childSubtree) {
            foreach ($this->mapSubtreeToNodeAggregateIds($childSubtree) as $descendantId) {
                $result[] = $descendantId;
            }
        }
        return $result;
    }
}
