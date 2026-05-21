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

    /**
     * Find all stale-translation records whose node aggregate lives inside the given subtree, in
     * that subtree's workspace, with its origin DSP.
     *
     * Important: this filters by `$subtree->node->originDimensionSpacePoint->hash` — so callers must
     * pass the **target-language** subtree (the one whose stale entries they want), NOT the
     * source-language subtree. Feeding the source subtree would never match anything because stale
     * records are written at the *target* origin.
     *
     * TODO: is this assumption correct?
     */
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
                // `ArrayParameterType::STRING` is REQUIRED for `IN (:placeholder)` expansion. Without
                // it, DBAL binds the value as a single parameter, the PDO driver coerces the array
                // to the literal string "Array" (with a PHP warning), and the IN clause matches
                // nothing. Discovered while wiring up the Retranslator: the finder was silently
                // returning empty results for every call.
                // TODO: Validate that assumption
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
