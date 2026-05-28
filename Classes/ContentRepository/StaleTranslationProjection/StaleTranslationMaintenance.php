<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Maintenance API for the StaleTranslation read model. Exposes the write operations needed by external
 * reconciliation flows (e.g. the `staletranslations:reconcile` CLI) so the projection's `apply()` path
 * remains the only place that reacts to events, but operators can still prune orphans the projection
 * cannot detect (descendant rows after a parent removal).
 *
 * @internal Only for consumption inside LostInTranslation.
 */
#[Flow\Proxy(false)]
final readonly class StaleTranslationMaintenance
{
    public function __construct(
        private Connection $dbal,
        private string $tableName,
    ) {
    }

    public function removeStaleRowsForNodeAggregate(WorkspaceName $workspaceName, NodeAggregateId $nodeAggregateId): int
    {
        return (int)$this->dbal->executeStatement(
            'DELETE FROM ' . $this->tableName
                . ' WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId',
            [
                'workspaceName' => $workspaceName->value,
                'nodeAggregateId' => $nodeAggregateId->value,
            ],
        );
    }
}
