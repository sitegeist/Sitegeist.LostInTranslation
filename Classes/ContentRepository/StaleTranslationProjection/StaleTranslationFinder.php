<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateIds;
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

    public function findBySubtree(Subtree $subtree): StaleTranslations
    {
        $staleTranslationRows = $this->dbal->executeQuery(
            <<<SQL
            SELECT * FROM {$this->tableName}
                WHERE workspaceName = :workspaceName
                    AND nodeAggregateId IN (:nodeAggregateIds)
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash
            SQL,
            [
                'workspaceName' => $subtree->node->workspaceName->value,
                'nodeAggregateIds' => $this->mapSubtreeToNodeAggregateIds($subtree)->toStringArray(),
                'originDimensionSpacePointHash' => $subtree->node->originDimensionSpacePoint->hash,
            ],
            [
                'nodeAggregateIds' => ArrayParameterType::STRING,
            ]
        )->fetchAllAssociative();

        return StaleTranslations::fromDatabaseRows($staleTranslationRows);
    }

    private function mapSubtreeToNodeAggregateIds(Subtree $subtree): NodeAggregateIds
    {
        $result = NodeAggregateIds::create($subtree->node->aggregateId);
        foreach ($subtree->children as $childSubtree) {
            $result = $result->merge($this->mapSubtreeToNodeAggregateIds($childSubtree));
        }

        return $result;
    }
}
