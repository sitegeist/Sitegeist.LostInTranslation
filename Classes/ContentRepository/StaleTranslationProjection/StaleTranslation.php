<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception as DbalException;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Read model for stale translations
 *
 * @internal Only for consumption inside Sitegeist.LostInTranslation.
 */
#[Flow\Proxy(false)]
final class StaleTranslation
{
    public function __construct(
        public ContentRepositoryId $contentRepositoryId,
        public WorkspaceName $workspaceName,
        public OriginDimensionSpacePoint $originDimensionSpacePoint,
        public NodeAggregateId $nodeAggregateId,
        public PropertyNames $propertyNames,
    ) {
    }

    /**
     * @param Connection $databaseConnection
     */
    public function addToDatabase(Connection $databaseConnection, string $tableName): void
    {
        try {
            $databaseConnection->insert($tableName, [
                'contentRepositoryId' => $this->contentRepositoryId->value,
                'workspaceName' => $this->workspaceName->value,
                'originDimensionSpacePoint' => $this->originDimensionSpacePoint->toJson(),
                'originDimensionSpacePointHash' => $this->originDimensionSpacePoint->hash,
                'nodeAggregateId' => $this->nodeAggregateId->value,
                'propertyNames' => \json_encode($this->propertyNames),
            ]);
        } catch (DbalException $e) {
            throw new \RuntimeException(sprintf('Failed to insert StaleTranslation to database: %s', $e->getMessage()), 1775824164, $e);
        }
    }

    public function updateToDatabase(Connection $databaseConnection, string $tableName): void
    {
        try {
            $databaseConnection->update(
                $tableName,
                [
                    'propertyNames' => \json_encode($this->propertyNames),
                ],
                [
                    'contentRepositoryId' => $this->contentRepositoryId->value,
                    'workspaceName' => $this->workspaceName->value,
                    'originDimensionSpacePointHash' => $this->originDimensionSpacePoint->hash,
                    'nodeAggregateId' => $this->nodeAggregateId->value,
                ]
            );
        } catch (DbalException $e) {
            throw new \RuntimeException(sprintf('Failed to update StaleTranslation in database: %s', $e->getMessage()), 1775824255, $e);
        }
    }

    /**
     * @param array<string,mixed> $databaseRow
     */
    public static function fromDatabaseRow(array $databaseRow): self
    {
        return new self(
            contentRepositoryId: ContentRepositoryId::fromString($databaseRow['contentRepositoryId']),
            workspaceName: WorkspaceName::fromString($databaseRow['workspaceName']),
            originDimensionSpacePoint: OriginDimensionSpacePoint::fromJsonString($databaseRow['originDimensionSpacePoint']),
            nodeAggregateId: NodeAggregateId::fromString($databaseRow['nodeAggregateId']),
            propertyNames: PropertyNames::fromArray(\json_decode($databaseRow['propertyNames'], true, JSON_THROW_ON_ERROR)),
        );
    }
}
