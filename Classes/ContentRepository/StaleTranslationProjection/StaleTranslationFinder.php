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

    /**
     * Find the stale-translation records for one (workspace, target-origin) slice — the slice the synchronizers and the
     * backend status actually act on. Scoped in SQL so callers no longer hydrate the whole projection via
     * {@see self::findAll()} and filter by `workspaceName` + `originDimensionSpacePointHash` in PHP.
     *
     * HOW A ROW IS KEYED. `$workspaceName` is the workspace whose content the row was written from — the projection
     * stamps every row with the `workspaceName` of the event it reacted to. `$originDimensionSpacePoint` is the TARGET
     * dimension that now owes a (re-)translation. A row reads "in workspace W, node N still owes a translation at
     * origin O": the workspace coordinate is not a target-vs-source distinction at all, which is what makes the callers
     * look inconsistent when they are not.
     *
     * ALL CALLERS PASS THE TARGET WORKSPACE, and must. It is the slice a run CLEARS — translating dispatches into the
     * target, so the resulting `NodePropertiesWereSet` closes the target's rows and leaves the source's alone (which is
     * what keeps re-running idempotent). Only the target's slice therefore converges to empty, which is also why
     * {@see SynchronizationStatusProvider} must count it or the backend module would never stop saying "out of sync".
     *
     * Passing the SOURCE workspace instead is very nearly undetectable, so do not conclude from a green test run that
     * it does not matter. Same-workspace the two are the same slice. Cross-workspace, every driver force-rebases the
     * target onto the source before reading, and the projection's `WorkspaceWasRebased` handler REPLACES the rebased
     * workspace's rows with a wholesale copy of its base's — so the slices are identical from that moment on. The only
     * run that skips the rebase is a dry run, and that short-circuits before it consumes the slice for translation.
     */
    public function findByWorkspaceAndOrigin(
        WorkspaceName $workspaceName,
        OriginDimensionSpacePoint $originDimensionSpacePoint,
    ): StaleTranslations {
        $staleTranslationRows = $this->dbal->executeQuery(
            <<<SQL
            SELECT * FROM {$this->tableName}
                WHERE workspaceName = :workspaceName
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash
            SQL,
            [
                'workspaceName' => $workspaceName->value,
                'originDimensionSpacePointHash' => $originDimensionSpacePoint->hash,
            ],
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
