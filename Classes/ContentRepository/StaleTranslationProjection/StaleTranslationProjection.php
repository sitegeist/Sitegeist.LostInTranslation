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
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\Projection\ProjectionInterface;
use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Dbal\DbalSchemaDiff;
use Neos\ContentRepository\Dbal\DbalSchemaFactory;
use Neos\EventStore\Model\EventEnvelope;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\ReferenceDimensionSpacePointResolver;

/**
 * @internal Only for consumption inside LostInTranslation.
 * @implements ProjectionInterface<StaleTranslationReadModel>
 */
#[Flow\Proxy(false)]
class StaleTranslationProjection implements ProjectionInterface
{
    private StaleTranslationReadModel $readModel;

    private string $itemTableName;

    private string $workspaceHierarchyTableName;

    private string $nodeAggregateTypeTableName;

    public function __construct(
        private readonly Connection $dbal,
        private readonly string $tableNamePrefix,
        private readonly ReferenceDimensionSpacePointResolver $referenceDimensionSpacePointResolver,
        private readonly NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory,
        private readonly NodeTypeManager $nodeTypeManager,
    ) {
        $this->itemTableName = $this->tableNamePrefix;
        $this->workspaceHierarchyTableName = $this->tableNamePrefix . '_ws_hierarchy';
        $this->nodeAggregateTypeTableName = $this->tableNamePrefix . '_nodeaggregate_type';
        $this->readModel = new StaleTranslationReadModel(
            staleTranslationFinder: new StaleTranslationFinder(
                dbal: $this->dbal,
                tableName: $this->itemTableName,
            ),
            nodeTypeResolver: new NodeTypeResolver(
                dbal: $this->dbal,
                tableName: $this->nodeAggregateTypeTableName,
                workspaceHierarchyTableName: $this->workspaceHierarchyTableName,
                nodeTypeManager: $nodeTypeManager,
            )
        );
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
            DbalSchemaFactory::columnForWorkspaceName('workspaceName', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForNodeAggregateId('nodeAggregateId', $platform)->setNotnull(true),
            DbalSchemaFactory::columnForDimensionSpacePoint('originDimensionSpacePoint', $platform)->setNotnull(false),
            DbalSchemaFactory::columnForDimensionSpacePointHash('originDimensionSpacePointHash', $platform)->setNotnull(true),
            (new Column('propertyNames', Type::getType(Types::JSON)))->setNotnull(true),
        ]);
        $staleTranslationTable->setPrimaryKey([
            'workspaceName',
            'nodeAggregateId',
            'originDimensionSpacePointHash'
        ]);

        $workspaceHierarchyTable = new Table(
            $this->workspaceHierarchyTableName,
            [
                DbalSchemaFactory::columnForWorkspaceName('parent_workspace_name', $platform)->setNotNull(true),
                DbalSchemaFactory::columnForWorkspaceName('child_workspace_name', $platform)->setNotnull(true),
            ]
        );
        $workspaceHierarchyTable->setPrimaryKey(['parent_workspace_name', 'child_workspace_name']);

        $nodeAggregateTypeTable = new Table(
            $this->nodeAggregateTypeTableName,
            [
                DbalSchemaFactory::columnForWorkspaceName('workspaceName', $platform)->setNotNull(true),
                DbalSchemaFactory::columnForNodeAggregateId('nodeAggregateId', $platform)->setNotnull(true),
                DbalSchemaFactory::columnForNodeTypeName('nodeTypeName', $platform)->setNotNull(true),
            ]
        );
        $nodeAggregateTypeTable->setPrimaryKey(['workspaceName', 'nodeAggregateId']);
        $nodeAggregateTypeTable->addIndex(['workspaceName'], 'workspaceName');

        $schema = DbalSchemaFactory::createSchemaWithTables($connection, [$staleTranslationTable, $workspaceHierarchyTable, $nodeAggregateTypeTable]);
        $statements = DbalSchemaDiff::determineRequiredSqlStatements($connection, $schema);

        return $statements;
    }

    public function resetState(): void
    {
        $this->dbal->exec('TRUNCATE ' . $this->itemTableName);
        $this->dbal->exec('TRUNCATE ' . $this->workspaceHierarchyTableName);
        $this->dbal->exec('TRUNCATE ' . $this->nodeAggregateTypeTableName);
    }

    public function apply(EventInterface $event, EventEnvelope $eventEnvelope): void
    {
        match ($event::class) {
            NodeAggregateWithNodeWasCreated::class => $this->whenNodeAggregateWithNodeWasCreated($event),
            // variation does not (yet) contain properties and thus is uneffective
            //NodeSpecializationVariantWasCreated::class => $this->whenNodeSpecializationVariantWasCreated($event),
            //NodeGeneralizationVariantWasCreated::class => $this->whenNodeGeneralizationVariantWasCreated($event),
            //NodePeerVariantWasCreated::class => $this->whenNodePeerVariantWasCreated($event),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($event),
            // @todo reference properties are still missing generally
            #NodeReferencesWereSet::class => $this->whenNodeReferencesWereSet($event),
            // We deliberately ignore hierarchy due to complexity reasons until depending projections are implemented.
            #NodeAggregateWasRemoved::class => $this->whenNodeAggregateWasRemoved($event),
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

            default => null,
        };
    }

    public function getState(): StaleTranslationReadModel
    {
        return $this->readModel;
    }

    private function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
        $targetDimensionSpacePoints = $this->referenceDimensionSpacePointResolver->resolveTargetDimensionSpacePoints(
            $event->originDimensionSpacePoint->toDimensionSpacePoint()
        );
        $this->memorizeNodeTypeName(
            nodeAggregateId: $event->nodeAggregateId,
            nodeTypeName: $event->nodeTypeName,
            workspaceName: $event->workspaceName,
        );

        $nodeType = $this->nodeTypeManager->getNodeType($event->nodeTypeName);
        if (!$nodeType) {
            return;
        }
        $staleTranslations = [];
        $translatablePropertyNames = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType)
            ->getPropertyNames();
        foreach ($translatablePropertyNames as $translatablePropertyName) {
            $initialPropertyValue = $event->initialPropertyValues->getProperty($translatablePropertyName->value);
            if ($initialPropertyValue !== null && $initialPropertyValue->value !== '') {
                $staleTranslations[] = $translatablePropertyName->value;
            }
        }

        foreach ($targetDimensionSpacePoints as $targetDimensionSpacePoint) {
            $this->dbal->insert(
                $this->itemTableName,
                [
                    'workspaceName' => $event->workspaceName->value,
                    'nodeAggregateId' => $event->nodeAggregateId->value,
                    'originDimensionSpacePoint' => $targetDimensionSpacePoint->toJson(),
                    'originDimensionSpacePointHash' => $targetDimensionSpacePoint->hash,
                    'propertyNames' => \json_encode($staleTranslations),
                ],
            );
        }
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeSpecializationVariantWasCreated(NodeSpecializationVariantWasCreated $event): void
    {
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event): void
    {
    }

    /** @phpstan-ignore method.unused */
    private function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event): void
    {
    }

    private function whenNodePropertiesWereSet(NodePropertiesWereSet $event): void
    {
        $nodeType = $this->readModel->nodeTypeResolver->resolveByNodeAggregateId(
            nodeAggregateId: $event->nodeAggregateId,
            workspaceName: $event->workspaceName
        );
        if (!$nodeType) {
            return;
        }
        $translatablePropertyNames = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType)
            ->getPropertyNames();
        $this->dbal->transactional(function () use ($event, $translatablePropertyNames) {
            $record = $this->dbal->executeQuery(
                'SELECT propertyNames FROM ' . $this->itemTableName
                    . ' WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash',
                [
                    'workspaceName' => $event->workspaceName->value,
                    'nodeAggregateId' => $event->nodeAggregateId->value,
                    'originDimensionSpacePointHash' => $event->originDimensionSpacePoint->hash,
                ],
            )->fetchAssociative();

            if ($record) {
                $currentPropertyNames = \json_decode($record['propertyNames'], true, 512, JSON_THROW_ON_ERROR);
                $updatedPropertyNames = array_merge(
                    array_keys($event->propertyValues->values),
                    $this->convertPropertyNamesToStringArray($event->propertiesToUnset),
                );
                $remainingPropertyNames = array_diff($currentPropertyNames, $updatedPropertyNames);
                $remainingPropertyNames = array_intersect(
                    $remainingPropertyNames,
                    $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                );

                if ($remainingPropertyNames === []) {
                    $this->dbal->delete(
                        $this->itemTableName,
                        [
                            'workspaceName' => $event->workspaceName->value,
                            'nodeAggregateId' => $event->nodeAggregateId->value,
                            'originDimensionSpacePointHash' => $event->originDimensionSpacePoint->hash,
                        ]
                    );
                } else {
                    $this->dbal->update(
                        $this->itemTableName,
                        [
                            'propertyNames' => \json_encode($remainingPropertyNames),
                        ],
                        [
                            'workspaceName' => $event->workspaceName->value,
                            'nodeAggregateId' => $event->nodeAggregateId->value,
                            'originDimensionSpacePointHash' => $event->originDimensionSpacePoint->hash,
                        ]
                    );
                }
            }
        });

        $targetDimensionSpacePoints = $this->referenceDimensionSpacePointResolver->resolveTargetDimensionSpacePoints(
            $event->originDimensionSpacePoint->toDimensionSpacePoint()
        );
        foreach ($targetDimensionSpacePoints as $targetDimensionSpacePoint) {
            $this->dbal->transactional(function () use ($event, $targetDimensionSpacePoint, $translatablePropertyNames) {
                $record = $this->dbal->executeQuery(
                    'SELECT propertyNames FROM ' . $this->itemTableName
                    . ' WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash',
                    [
                        'workspaceName' => $event->workspaceName->value,
                        'nodeAggregateId' => $event->nodeAggregateId->value,
                        'originDimensionSpacePointHash' => $targetDimensionSpacePoint->hash,
                    ],
                )->fetchAssociative();

                $updatedPropertyNames = array_merge(
                    array_keys($event->propertyValues->values),
                    $this->convertPropertyNamesToStringArray($event->propertiesToUnset),
                );
                if ($record) {
                    $currentPropertyNames = \json_decode($record['propertyNames'], true, 512, JSON_THROW_ON_ERROR);
                    $newPropertyNames = array_unique(array_merge($currentPropertyNames, $updatedPropertyNames));
                    $newPropertyNames = array_intersect(
                        $newPropertyNames,
                        $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                    );

                    if ($newPropertyNames === []) {
                        $this->dbal->delete(
                            $this->itemTableName,
                            [
                                'workspaceName' => $event->workspaceName->value,
                                'nodeAggregateId' => $event->nodeAggregateId->value,
                                'originDimensionSpacePointHash' => $targetDimensionSpacePoint->hash,
                            ]
                        );
                    } else {
                        $this->dbal->update(
                            $this->itemTableName,
                            [
                                'propertyNames' => \json_encode($newPropertyNames),
                            ],
                            [
                                'workspaceName' => $event->workspaceName->value,
                                'nodeAggregateId' => $event->nodeAggregateId->value,
                                'originDimensionSpacePointHash' => $targetDimensionSpacePoint->hash,
                            ]
                        );
                    }
                } else {
                    $newPropertyNames = array_intersect(
                        $updatedPropertyNames,
                        $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                    );

                    if ($newPropertyNames !== []) {
                        $this->dbal->insert(
                            $this->itemTableName,
                            [
                                'workspaceName' => $event->workspaceName->value,
                                'nodeAggregateId' => $event->nodeAggregateId->value,
                                'originDimensionSpacePoint' => $targetDimensionSpacePoint->toJson(),
                                'originDimensionSpacePointHash' => $targetDimensionSpacePoint->hash,
                                'propertyNames' => \json_encode($newPropertyNames),
                            ],
                        );
                    }
                }
            });
        }
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeReferencesWereSet(NodeReferencesWereSet $event): void
    {
        // todo: track reference properties
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
    }

    private function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event): void
    {
        /**
         * @var array<int,array{
         *     workspaceName: string,
         *     nodeAggregateId: string,
         *     originDimensionSpacePointHash: string,
         *     propertyNames: string
         * }> $affectedRecords
         */
        $affectedRecords = $this->dbal->executeQuery(
            'SELECT * FROM ' . $this->itemTableName . ' WHERE nodeAggregateId = :nodeAggregateId AND workspaceName = :workspaceName',
            [
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'workspaceName' => $event->workspaceName->value,
            ],
        )->fetchAllAssociative();
        $nodeType = $this->nodeTypeManager->getNodeType($event->newNodeTypeName);
        if (!$nodeType) {
            return;
        }

        $translatablePropertyNames = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType)
            ->getPropertyNames();
        $propertiesWithDefaultValue = [];
        foreach ($nodeType->getDefaultValuesForProperties() as $propertyName => $defaultValue) {
            /** @todo implement default value translation for objects */
            if (is_string($defaultValue)) {
                $propertiesWithDefaultValue[] = $propertyName;
            }
        }

        foreach ($affectedRecords as $affectedRecord) {
            $currentStaleProperties = \json_decode($affectedRecord['propertyNames'], true, 512, JSON_THROW_ON_ERROR);
            $newStaleProperties = array_merge($currentStaleProperties, $propertiesWithDefaultValue);
            $newStaleProperties = array_intersect(
                $newStaleProperties,
                array_map(
                    fn (PropertyName $propertyName): string => $propertyName->value,
                    iterator_to_array($translatablePropertyNames),
                )
            );
            if ($newStaleProperties != $currentStaleProperties) {
                $this->dbal->update(
                    $this->itemTableName,
                    [
                        'propertyNames' => \json_encode($newStaleProperties, JSON_THROW_ON_ERROR),
                    ],
                    [
                        'nodeAggregateId' => $affectedRecord['nodeAggregateId'],
                        'workspaceName' => $affectedRecord['workspaceName'],
                        'originDimensionSpacePointHash' => $affectedRecord['originDimensionSpacePointHash']
                    ],
                );
            }
        }

        $this->memorizeNodeTypeName(
            nodeAggregateId: $event->nodeAggregateId,
            nodeTypeName: $event->newNodeTypeName,
            workspaceName: $event->workspaceName,
        );
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
        $this->clearNodeTypeMemory($event->workspaceName);
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

        $this->replaceWorkspaceEntries($event->workspaceName, $event->baseWorkspaceName);
        $this->clearNodeTypeMemory($event->workspaceName);
    }

    private function whenWorkspaceWasRemoved(WorkspaceWasRemoved $event): void
    {
        $this->dbal->delete(
            $this->workspaceHierarchyTableName,
            [
                'child_workspace_name' => $event->workspaceName->value,
            ]
        );
        $this->dbal->delete(
            $this->itemTableName,
            [
                'workspaceName' => $event->workspaceName->value,
            ]
        );
        $this->clearNodeTypeMemory($event->workspaceName);
    }

    private function whenWorkspaceWasPublished(WorkspaceWasPublished $event): void
    {
        $this->replaceWorkspaceEntries($event->sourceWorkspaceName, $event->targetWorkspaceName);
        $this->clearNodeTypeMemory($event->sourceWorkspaceName);
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
        $this->clearNodeTypeMemory($event->workspaceName);
    }

    private function whenDimensionSpacePointWasMoved(DimensionSpacePointWasMoved $event): void
    {
        $this->dbal->update(
            $this->itemTableName,
            [
                'originDimensionSpacePoint' => $event->target->toJson(),
                'originDimensionSpacePointHash' => $event->target->hash,
            ],
            [
                'originDimensionSpacePointHash' => $event->source->hash,
            ],
        );
    }

    private function replaceWorkspaceEntries(WorkspaceName $workspaceName, WorkspaceName $baseWorkspaceName): void
    {
        $this->dbal->transactional(function () use ($workspaceName, $baseWorkspaceName) {
            $this->dbal->executeStatement(
                'DELETE FROM ' . $this->itemTableName . ' WHERE workspaceName = :workspaceName',
                [
                    'workspaceName' => $workspaceName->value,
                ]
            );

            $copyStatement = <<<SQL
            INSERT INTO {$this->itemTableName} (
                workspaceName,
                nodeAggregateId,
                originDimensionSpacePoint,
                originDimensionSpacePointHash,
                propertyNames
            )
            SELECT
                :workspaceName AS workspaceName,
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
                    'workspaceName' => $workspaceName->value,
                    'baseWorkspaceName' => $baseWorkspaceName->value,
                ]
            );
        });
    }

    /**
     * @return array<int,string>
     */
    private function convertPropertyNamesToStringArray(PropertyNames $propertyNames): array
    {
        return array_map(
            fn (PropertyName $propertyName): string => $propertyName->value,
            iterator_to_array($propertyNames),
        );
    }

    private function memorizeNodeTypeName(
        NodeAggregateId $nodeAggregateId,
        NodeTypeName $nodeTypeName,
        WorkspaceName $workspaceName,
    ): void {
        $record = $this->dbal->fetchAssociative(
            'SELECT * FROM ' . $this->nodeAggregateTypeTableName . ' WHERE workspaceName = :workspaceName AND nodeAggregateId = :nodeAggregateId',
            [
                'workspaceName' => $workspaceName->value,
                'nodeAggregateId' => $nodeAggregateId->value,
            ],
        );
        if ($record) {
            $this->dbal->update(
                table: $this->nodeAggregateTypeTableName,
                data: [
                    'nodeTypeName' => $nodeTypeName->value,
                ],
                criteria: [
                    'workspaceName' => $workspaceName->value,
                    'nodeAggregateId' => $nodeAggregateId->value,
                ],
            );
        } else {
            $this->dbal->insert(
                table: $this->nodeAggregateTypeTableName,
                data: [
                    'workspaceName' => $workspaceName->value,
                    'nodeAggregateId' => $nodeAggregateId->value,
                    'nodeTypeName' => $nodeTypeName->value,
                ],
            );
        }
    }

    private function clearNodeTypeMemory(WorkspaceName $workspaceName): void
    {
        $this->dbal->executeStatement(
            'DELETE FROM ' . $this->nodeAggregateTypeTableName . ' WHERE workspaceName = :workspaceName',
            [
                'workspaceName' => $workspaceName->value,
            ],
        );
    }
}
