<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DBALException;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\SchemaException;
use Doctrine\DBAL\Schema\Table;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Neos\ContentRepository\Core\EventStore\EventInterface;
use Neos\ContentRepository\Core\Feature\DimensionSpaceAdjustment\Event\DimensionSpacePointWasMoved;
use Neos\ContentRepository\Core\Feature\NodeCreation\Event\NodeAggregateWithNodeWasCreated;
use Neos\ContentRepository\Core\Feature\NodeModification\Event\NodePropertiesWereSet;
use Neos\ContentRepository\Core\Feature\NodeReferencing\Event\NodeReferencesWereSet;
use Neos\ContentRepository\Core\Feature\NodeRemoval\Event\NodeAggregateWasRemoved;
use Neos\ContentRepository\Core\Feature\NodeTypeChange\Event\NodeAggregateTypeWasChanged;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeGeneralizationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodePeerVariantWasCreated;
use Neos\ContentRepository\Core\Feature\NodeVariation\Event\NodeSpecializationVariantWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceCreation\Event\WorkspaceWasCreated;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceBaseWorkspaceWasChanged;
use Neos\ContentRepository\Core\Feature\WorkspaceModification\Event\WorkspaceWasRemoved;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasDiscarded;
use Neos\ContentRepository\Core\Feature\WorkspacePublication\Event\WorkspaceWasPublished;
use Neos\ContentRepository\Core\Feature\WorkspaceRebase\Event\WorkspaceWasRebased;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Dbal\DbalSchemaDiff;
use Neos\ContentRepository\Dbal\DbalSchemaFactory;
use Neos\EventStore\Model\EventEnvelope;

/**
 * @internal Only for consumption inside LostInTranslation.
 * @implements ProjectionInterface<StaleTranslationFinder>
 */
class StaleTranslationProjection implements ProjectionInterface
{
    private StaleTranslationFinder $staleTranslationFinder;

    private string $itemTableName;

    private string $workspaceHierarchyTableName;

    public function __construct(
        private readonly Connection $dbal,
        private readonly string $tableNamePrefix,
    ) {
        $this->itemTableName = $this->tableNamePrefix;
        $this->workspaceHierarchyTableName = $this->tableNamePrefix . '_workspace_hierarchy';
        $this->staleTranslationFinder = new StaleTranslationFinder($this->dbal, $this->itemTableName);
    }

    /**
     * @return void
     * @throws DBALException
     */
    public function setUp(): void
    {
        foreach ($this->determineRequiredSqlStatements() as $statement) {
            $this->dbal->executeStatement($statement);
        }
    }

    public function status(): ProjectionStatus
    {
        try {
            $this->dbal->connect();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to connect to database: %s', $e->getMessage()));
        }
        try {
            $requiredSqlStatements = $this->determineRequiredSqlStatements();
        } catch (\Throwable $e) {
            return ProjectionStatus::error(sprintf('Failed to determine required SQL statements: %s', $e->getMessage()));
        }
        if ($requiredSqlStatements !== []) {
            return ProjectionStatus::setupRequired(sprintf('The following SQL statement%s required: %s', count($requiredSqlStatements) !== 1 ? 's are' : ' is', implode(chr(10), $requiredSqlStatements)));
        }
        return ProjectionStatus::ok();
    }

    /**
     * @return array<string>
     * @throws DBALException
     * @throws SchemaException
     */
    private function determineRequiredSqlStatements(): array
    {
        $connection = $this->dbal;
        $platform = $this->dbal->getDatabasePlatform();

        $staleTranslationTable = new Table($this->itemTableName, [
            (new Column('contentRepositoryId', Type::getType(Types::BINARY)))->setLength(16)->setNotnull(true),
            DbalSchemaFactory::columnForWorkspaceName('workspaceName', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForNodeAggregateId('nodeAggregateId', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForDimensionSpacePoint('originDimensionSpacePoint', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForDimensionSpacePointHash('originDimensionSpacePointHash', $platform)->setNotnull(true),
            (new Column('propertyNames', Type::getType(Types::JSON)))->setNotnull(true),
        ]);
        $staleTranslationTable->setPrimaryKey([
            'contentRepositoryId',
            'workspaceName',
            'nodeAggregateId',
            'originDimensionSpacePointHash'
        ]);

        $workspaceHierarchyTable = new Table(
            $this->workspaceHierarchyTableName,
            [
                DbalSchemaFactory::columnForWorkspaceName('parent_workspace_name', $platform)->setNotNull(true),
                DbalSchemaFactory::columnForNodeAggregateId('child_workspace_name', $platform)->setNotnull(true),
            ]
        );
        $workspaceHierarchyTable->setPrimaryKey(['parent_workspace_name', 'child_workspace_name']);

        $schema = DbalSchemaFactory::createSchemaWithTables($connection, [$staleTranslationTable, $workspaceHierarchyTable]);
        $statements = DbalSchemaDiff::determineRequiredSqlStatements($connection, $schema);

        return $statements;
    }

    public function resetState(): void
    {
        $this->dbal->exec('TRUNCATE ' . $this->itemTableName);
        $this->dbal->exec('TRUNCATE ' . $this->workspaceHierarchyTableName);
    }

    public function apply(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        match ($event::class) {
            NodeAggregateWithNodeWasCreated::class => $this->whenNodeAggregateWithNodeWasCreated($event),
            NodeSpecializationVariantWasCreated::class => $this->whenNodeSpecializationVariantWasCreated($event),
            NodeGeneralizationVariantWasCreated::class => $this->whenNodeGeneralizationVariantWasCreated($event),
            NodePeerVariantWasCreated::class => $this->whenNodePeerVariantWasCreated($event),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($event),
            NodeReferencesWereSet::class => $this->whenNodeReferencesWereSet($event),
            NodeAggregateWasRemoved::class => $this->whenNodeAggregateWasRemoved($event),
            NodeAggregateTypeWasChanged::class => $this->whenNodeAggregateTypeWasChanged($event),

            WorkspaceWasCreated::class => $this->whenWorkspaceWasCreated($event),
            WorkspaceBaseWorkspaceWasChanged::class => $this->whenWorkspaceBaseWorkspaceWasChanged($event),
            WorkspaceWasRebased::class => $this->whenWorkspaceWasRebased($event),
            WorkspaceWasPublished::class => $this->whenWorkspaceWasPublished($event),
            WorkspaceWasDiscarded::class => $this->whenWorkspaceWasDiscarded($event),
            WorkspaceWasRemoved::class => $this->whenWorkspaceWasRemoved($event),

            DimensionSpacePointWasMoved::class => $this->whenDimensionSpacePointWasMoved($event),

            // we only need to handle events that actually affect properties; pure edge operations are irrelevant
            // RootNodeAggregateWithNodeWasCreated is explicitly unhandled
            // SubtreeWasTagged is explicitly unhandled
            // SubtreeWasUntagged is explicitly unhandled
            // NodeAggregateWasMoved is explicitly unhandled
            // NodeAggregateNameWasChanged is explicitly unhandled

            // we also only care about workspaces, not content streams
            // ContentStreamWasCreated is explicitly unhandled
            // ContentStreamWasForked is explicitly unhandled
            // ContentStreamWasClosed is explicitly unhandled
            // ContentStreamWasReopened is explicitly unhandled
            // ContentStreamWasRemoved is explicitly unhandled

            // DimensionSpacePointWasMoved is unhandled, because other workspaces MUST NOT contain changes i.e. nothing needs to be adjusted
            default => null,
        };
    }

    public function getState(): StaleTranslationFinder
    {
        return $this->staleTranslationFinder;
    }

    private function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
    }

    private function whenNodeSpecializationVariantWasCreated(NodeSpecializationVariantWasCreated $event): void
    {
    }

    private function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event): void
    {
    }

    private function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event): void
    {
    }

    private function whenNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
    }

    private function whenNodeReferencesWereSet(NodeReferencesWereSet $event): void
    {
        // todo: track reference properties
    }

    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
    }

    private function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event): void
    {
    }

    private function whenWorkspaceWasCreated(WorkspaceWasCreated $event): void
    {
        $this->dbal->insert(
            $this->workspaceHierarchyTableName,
            [
                'parent_workspace_name' => $event->baseWorkspaceName->value,
                'child_workspace_name' => $event->workspaceName->value,
            ]
        );
    }

    private function whenWorkspaceWasRebased(WorkspaceWasRebased $event): void
    {
        $workspaceHierarchyRecord = $this->dbal->executeQuery(
            'SELECT * FROM ' . $this->workspaceHierarchyTableName . ' WHERE child_workspace_name = :childWorkspaceName',
            [
                'childWorkspaceName' => $event->workspaceName,
            ]
        )->fetchAssociative();

        if (!$workspaceHierarchyRecord) {
            throw new \Exception('Could not resolve base workspace for workspace ' . $event->workspaceName->value, 1775830124);
        }

        $this->replaceWorkspaceEntries($event->workspaceName, WorkspaceName::fromString($workspaceHierarchyRecord['parent_workspace_name']));
    }

    private function whenWorkspaceBaseWorkspaceWasChanged(WorkspaceBaseWorkspaceWasChanged $event): void
    {
        $this->dbal->update(
            $this->workspaceHierarchyTableName,
            [
                'parent_workspace_name' => $event->baseWorkspaceName->value,
            ],
            [
                'child_workspace_name' => $event->workspaceName->value,
            ]
        );
    }

    private function whenWorkspaceWasRemoved(WorkspaceWasRemoved $event): void
    {
        $this->dbal->delete(
            $this->workspaceHierarchyTableName,
            [
                'child_workspace_name' => $event->workspaceName->value,
            ]
        );
    }

    private function whenWorkspaceWasPublished(WorkspaceWasPublished $event): void
    {
        $this->replaceWorkspaceEntries($event->sourceWorkspaceName, $event->targetWorkspaceName);
    }

    private function whenWorkspaceWasDiscarded(WorkspaceWasDiscarded $event): void
    {
        $workspaceHierarchyRecord = $this->dbal->executeQuery(
            'SELECT * FROM ' . $this->workspaceHierarchyTableName . ' WHERE child_workspace_name = :childWorkspaceName',
            [
                'childWorkspaceName' => $event->workspaceName,
            ]
        )->fetchAssociative();

        if (!$workspaceHierarchyRecord) {
            throw new \Exception('Could not resolve base workspace for workspace ' . $event->workspaceName->value, 1775830079);
        }

        $this->replaceWorkspaceEntries($event->workspaceName, WorkspaceName::fromString($workspaceHierarchyRecord['parent_workspace_name']));
    }

    private function whenDimensionSpacePointWasMoved(DimensionSpacePointWasMoved $event): void
    {

    }

    private function replaceWorkspaceEntries(WorkspaceName $workspaceName, WorkspaceName $baseWorkspaceName): void
    {
        $this->dbal->executeStatement(
            'DELETE FROM ' . $this->itemTableName . ' WHERE workspace_name = :workspaceName',
            [
                'workspaceName' => $workspaceName->value,
            ]
        );

        $copyStatement = <<<SQL
            INSERT INTO {$this->itemTableName} (
                contentRepositoryId,
                workspaceName,
                nodeAggregateId,
                originDimensionSpacePoint,
                originDimensionSpacePointHash,
                propertyNames,
            )
            SELECT
                i.contentRepositoryId,
                "{$workspaceName->value}" AS workspaceName,
                i.nodeAggregateId,
                i.originDimensionSpacePoint,
                i.originDimensionSpacePointHash,
                i.propertyNames
            FROM
                {$this->itemTableName} i
                WHERE i.workspaceName = :baseWorkspaceName
        SQL;
        $this->dbal->executeStatement(
            $copyStatement,
            [
                'baseWorkspaceName' => $baseWorkspaceName->value,
            ]
        );
    }
}
