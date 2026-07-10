<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
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
        private readonly string $tableName,
    ) {
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

    public function findByWorkspace(WorkspaceName $workspaceName, OriginDimensionSpacePoint $targetOriginSpacePoint): StaleTranslations
    {
        $staleTranslationRows = $this->dbal->executeQuery(
            <<<SQL
            SELECT * FROM {$this->tableName}
                WHERE workspaceName = :workspaceName
                AND originDimensionSpacePointHash = :originDimensionSpacePointHash
            SQL,
            [
                'workspaceName' => $workspaceName->value,
                'originDimensionSpacePointHash' => $targetOriginSpacePoint->hash,
            ]
        )->fetchAllAssociative();

        return StaleTranslations::fromDatabaseRows($staleTranslationRows);
    }

    /**
     * Find stale-translation records for nodes in the given **source-language** subtree at the
     * **target-language** origin (where stale records live). Results are returned in depth-first
     * pre-order of the subtree so callers can dispatch commands in hierarchical order.
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
                // Required for `IN (:placeholder)` expansion — otherwise DBAL binds the array as a
                // single parameter and PDO coerces it to the literal string "Array".
                'nodeAggregateIds' => ArrayParameterType::STRING,
            ]
        )->fetchAllAssociative();

        // SQL makes no order guarantee — reorder in PHP to match the source subtree walk.
        $orderIndex = array_flip($orderedIds);
        usort(
            $staleTranslationRows,
            static fn(array $a, array $b): int => $orderIndex[$a['nodeAggregateId']] <=> $orderIndex[$b['nodeAggregateId']]
        );

        return StaleTranslations::fromDatabaseRows($staleTranslationRows);
    }

    /**
     * Flatten the subtree into depth-first pre-order ids. Returns `list<string>` (not
     * `NodeAggregateIds`) because the iteration order is part of the contract here.
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
