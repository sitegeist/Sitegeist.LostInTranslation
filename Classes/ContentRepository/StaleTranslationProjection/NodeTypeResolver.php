<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Finder for node types by aggregate id
 *
 * @internal Only for consumption inside LostInTranslation.
 */
#[Flow\Proxy(false)]
final readonly class NodeTypeResolver implements ProjectionStateInterface
{
    public function __construct(
        private Connection $dbal,
        private string $tableName,
        private string $workspaceHierarchyTableName,
        private NodeTypeManager $nodeTypeManager,
    ) {
    }

    public function resolveByNodeAggregateId(NodeAggregateId $nodeAggregateId, WorkspaceName $workspaceName): ?NodeType
    {
        $nodeTypeName = $this->dbal->executeQuery(
            <<<SQL
            SELECT nodeTypeName FROM {$this->tableName}
                WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId
            SQL,
            [
                'workspaceName' => $workspaceName->value,
                'nodeAggregateId' => $nodeAggregateId->value,
            ]
        )->fetchOne();

        if (!$nodeTypeName) {
            $parentWorkspaceName = $this->findParentWorkspaceName($workspaceName);
            if ($parentWorkspaceName) {
                return $this->resolveByNodeAggregateId($nodeAggregateId, $parentWorkspaceName);
            } else {
                return null;
            }
        }

        return $this->nodeTypeManager->getNodeType($nodeTypeName);
    }

    public function findParentWorkspaceName(WorkspaceName $workspaceName): ?WorkspaceName
    {
        $parentWorkspaceName = $this->dbal->fetchOne(
            'SELECT parent_workspace_name FROM ' . $this->workspaceHierarchyTableName . ' WHERE child_workspace_name = :workspaceName',
            [
                'workspaceName' => $workspaceName->value,
            ],
        );

        return $parentWorkspaceName
            ? WorkspaceName::fromString($parentWorkspaceName)
            : null;
    }

    /**
     * For testing purposes only
     * @return array<int,array{workspaceName: string, nodeAggregateId: string, nodeTypeName: string}>
     */
    public function findAll(): array
    {
        return $this->dbal->executeQuery('SELECT * FROM ' . $this->tableName)
            ->fetchAllAssociative();
    }
}
