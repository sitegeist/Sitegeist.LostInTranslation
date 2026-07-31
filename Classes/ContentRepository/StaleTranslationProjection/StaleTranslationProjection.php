<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\ArrayParameterType;
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
            ),
            staleTranslationMaintenance: new StaleTranslationMaintenance(
                dbal: $this->dbal,
                tableName: $this->itemTableName,
            ),
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
            // A variant carries the (untranslated) source properties until the cascaded SetNodeProperties translates
            // them — so for a node WITH translatable properties the stale record stays valid and is cleared later by
            // NodePropertiesWereSet. But a property-less node (e.g. a tethered ContentCollection) is recorded with an
            // empty property list and never receives a SetNodeProperties, so its stale record would linger forever;
            // creating its variant fully satisfies it, so we drop the empty record here.
            NodeSpecializationVariantWasCreated::class => $this->whenNodeSpecializationVariantWasCreated($event),
            NodeGeneralizationVariantWasCreated::class => $this->whenNodeGeneralizationVariantWasCreated($event),
            NodePeerVariantWasCreated::class => $this->whenNodePeerVariantWasCreated($event),
            NodePropertiesWereSet::class => $this->whenNodePropertiesWereSet($event),
            // @todo reference properties are still missing generally — NodeReferencesWereSet not yet handled
            // Drops the directly-removed aggregate's stale rows scoped to the affected dimensions.
            // Descendants are NOT cascaded — the CR does not emit follow-up removal events for
            // children, so descendant stale rows linger until their own removal event arrives.
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

            default => null,
        };
    }

    public function getState(): StaleTranslationReadModel
    {
        return $this->readModel;
    }

    private function whenNodeAggregateWithNodeWasCreated(NodeAggregateWithNodeWasCreated $event): void
    {
        $targetDimensionSpacePoints = $this->referenceDimensionSpacePointResolver->findAllTargetDimensionSpacePoints(
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
        if ($nodeType->getConfiguration('options.automaticTranslation') !== true) {
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
        $this->clearStructuralStaleRecord($event->workspaceName, $event->nodeAggregateId, $event->specializationOrigin->hash);
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeGeneralizationVariantWasCreated(NodeGeneralizationVariantWasCreated $event): void
    {
        $this->clearStructuralStaleRecord($event->workspaceName, $event->nodeAggregateId, $event->generalizationOrigin->hash);
    }

    /** @phpstan-ignore method.unused */
    private function whenNodePeerVariantWasCreated(NodePeerVariantWasCreated $event): void
    {
        $this->clearStructuralStaleRecord($event->workspaceName, $event->nodeAggregateId, $event->peerOrigin->hash);
    }

    /**
     * Drop a stale record at the variant's target origin when it has NO translatable properties to translate (an empty
     * property list). Such records exist only to mirror structure (e.g. a tethered ContentCollection); once the variant
     * exists there is nothing left to do for them. Records that still list translatable properties are left untouched —
     * they are cleared by the subsequent translated {@see NodePropertiesWereSet}.
     *
     * Implemented as a single DELETE keyed by the full primary key plus an exact match on the serialized empty list.
     * Project-wide we always write `propertyNames` via `json_encode($staleTranslations)`, and the empty array
     * canonicalizes to exactly `'[]'`, so a string compare suffices and stays portable across MariaDB/MySQL and any
     * other Doctrine platform the projection might run on.
     */
    private function clearStructuralStaleRecord(
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        string $targetOriginDimensionSpacePointHash,
    ): void {
        $this->dbal->executeStatement(
            'DELETE FROM ' . $this->itemTableName
                . ' WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId
                    AND originDimensionSpacePointHash = :originDimensionSpacePointHash
                    AND propertyNames = :emptyPropertyNames',
            [
                'workspaceName' => $workspaceName->value,
                'nodeAggregateId' => $nodeAggregateId->value,
                'originDimensionSpacePointHash' => $targetOriginDimensionSpacePointHash,
                'emptyPropertyNames' => '[]',
            ],
        );
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
        if ($nodeType->getConfiguration('options.automaticTranslation') !== true) {
            return;
        }
        $translatablePropertyNames = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType)
            ->getPropertyNames();
        $targetDimensionSpacePoints = $this->referenceDimensionSpacePointResolver->findAllTargetDimensionSpacePoints($event->originDimensionSpacePoint->toDimensionSpacePoint());
        $this->dbal->transactional(function () use ($event, $translatablePropertyNames, $targetDimensionSpacePoints) {
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
                // `array_diff` / `array_intersect` PRESERVE keys, so the surviving names can end up at non-zero
                // offsets (e.g. `[1 => 'text']`). Re-index before encoding — `json_encode` would otherwise emit a JSON
                // object (`{"1":"text"}`) instead of the list (`["text"]`) every other reader of this column expects.
                $remainingPropertyNames = array_values(array_intersect(
                    array_diff($currentPropertyNames, $updatedPropertyNames),
                    $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                ));

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

            foreach ($targetDimensionSpacePoints as $targetDimensionSpacePoint) {
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
                    // Re-indexed at the assignment (see the source-side branch above): `array_unique` /
                    // `array_intersect` preserve keys, and this column must always hold a JSON list.
                    $newPropertyNames = array_values(array_intersect(
                        array_unique(array_merge($currentPropertyNames, $updatedPropertyNames)),
                        $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                    ));

                    // A source-side property set can only ADD newly-stale properties to a target
                    // record (a union with the current set); it never makes a target translation
                    // fresh. So we never delete here. An empty result means this was a structural
                    // row ('[]') and/or the changed property was not translatable — the row must be
                    // preserved. Removing target rows is reserved for retranslation/sync, variant
                    // creation, and node/workspace lifecycle events.
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
                } else {
                    // Re-indexed for the same reason as the source-side branch above: `array_intersect` preserves
                    // keys, and this column must always hold a JSON list.
                    $newPropertyNames = array_values(array_intersect(
                        $updatedPropertyNames,
                        $this->convertPropertyNamesToStringArray($translatablePropertyNames)
                    ));

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
            }
        });
    }

    /** @phpstan-ignore method.unused */
    private function whenNodeAggregateWasRemoved(NodeAggregateWasRemoved $event): void
    {
        // Stale rows live at TARGET dimensions (e.g. "de" when "en" is the source). The event's
        // affectedCoveredDimensionSpacePoints lists the DSPs the aggregate physically covered — which differs depending
        // on what the user removed:
        //   - target variant only (e.g. an editor deletes the "de" variant): the target DSP is in the affected set,
        //     matches the stale row directly.
        //   - source-only aggregate (no variant ever created): only the source DSP is affected, and the stale row sits
        //     at the unaffected target DSP — we must fan out via referenceLanguage to reach it.
        //   - both: both DSPs are in the affected set; fan-out is a no-op but harmless.
        // Unioning each affected DSP with its referenceLanguage targets covers all three cases in one DELETE.
        $affectedHashes = [];
        foreach ($event->affectedCoveredDimensionSpacePoints as $dimensionSpacePoint) {
            $affectedHashes[$dimensionSpacePoint->hash] = true;
            foreach ($this->referenceDimensionSpacePointResolver->findAllTargetDimensionSpacePoints($dimensionSpacePoint) as $targetDimensionSpacePoint) {
                $affectedHashes[$targetDimensionSpacePoint->hash] = true;
            }
        }
        if ($affectedHashes === []) {
            return;
        }
        $this->dbal->executeStatement(
            'DELETE FROM ' . $this->itemTableName
                . ' WHERE workspaceName = :workspaceName
                    AND nodeAggregateId = :nodeAggregateId
                    AND originDimensionSpacePointHash IN (:affectedDimensionSpacePointHashes)',
            [
                'workspaceName' => $event->workspaceName->value,
                'nodeAggregateId' => $event->nodeAggregateId->value,
                'affectedDimensionSpacePointHashes' => array_keys($affectedHashes),
            ],
            // Required for `IN (:placeholder)` expansion, as in StaleTranslationFinder. Not the deprecated
            // Connection::PARAM_STR_ARRAY, which DBAL 4 removes.
            ['affectedDimensionSpacePointHashes' => ArrayParameterType::STRING],
        );
    }

    private function whenNodeAggregateTypeWasChanged(NodeAggregateTypeWasChanged $event): void
    {
        /** @var array<int,array{workspaceName: string, nodeAggregateId: string, originDimensionSpacePointHash: string, propertyNames: string}> $affectedRecords */
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

        // A node retyped into a type excluded from automatic translation has no translatable properties left. Keeping
        // the set empty lets the array_intersect below clear the stale set, so the records are deleted rather than
        // lingering for a type that is never translated.
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $translatablePropertyNames = $directive->enabled
            ? $directive->getPropertyNames()
            : PropertyNames::createEmpty();
        $propertiesWithDefaultValue = [];
        foreach ($nodeType->getDefaultValuesForProperties() as $propertyName => $defaultValue) {
            // A default value makes its property a translation candidate when it actually carries content: a non-empty
            // string, or a non-empty array — the latter an object-typed property's default, whose translatable leaves a
            // TranslationConnector extracts. `getDefaultValuesForProperties()` hands back the RAW configuration without
            // any type conversion, so those two are the only shapes that can carry text; an int/float/bool default
            // never can, whatever type the property declares.
            //
            // Enumerated rather than `!empty()`, which also accepts `true` / `1` / `1.0`. No observable behaviour
            // rides on the difference, and neither shall any: a bool/int default reaches the stale set anyway — not
            // from here, but because `NodeTypeChange` emits a `NodePropertiesWereSet` right behind the type change
            // that materialises the new type's missing defaults through the property converter, so a `string`-typed
            // property whose default was mistyped in YAML really does end up holding "1". This test is about the
            // SHAPE of a configured value, and now says only that.
            $carriesContent = match (true) {
                is_string($defaultValue) => $defaultValue !== '',
                is_array($defaultValue) => $defaultValue !== [],
                default => false,
            };
            if ($carriesContent) {
                $propertiesWithDefaultValue[] = $propertyName;
            }
        }

        foreach ($affectedRecords as $affectedRecord) {
            $currentStaleProperties = \json_decode($affectedRecord['propertyNames'], true, 512, JSON_THROW_ON_ERROR);
            $newStaleProperties = array_merge($currentStaleProperties, $propertiesWithDefaultValue);
            $newStaleProperties = array_values(array_unique(array_intersect(
                $newStaleProperties,
                array_map(
                    fn (PropertyName $propertyName): string => $propertyName->value,
                    iterator_to_array($translatablePropertyNames),
                )
            )));
            // An already-empty record does not CHANGE when the node is retyped, but it must still be dropped when
            // the new type is excluded from automatic translation: an empty record is only legitimate for an
            // enabled type (e.g. a tethered ContentCollection without translatable properties), and
            // {@see \Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder} relies on stale
            // records existing only for translation-enabled types.
            if ($newStaleProperties == $currentStaleProperties && $directive->enabled) {
                continue;
            }
            if ($newStaleProperties === []) {
                $this->dbal->delete(
                    $this->itemTableName,
                    [
                        'nodeAggregateId' => $affectedRecord['nodeAggregateId'],
                        'workspaceName' => $affectedRecord['workspaceName'],
                        'originDimensionSpacePointHash' => $affectedRecord['originDimensionSpacePointHash']
                    ],
                );
            } else {
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
