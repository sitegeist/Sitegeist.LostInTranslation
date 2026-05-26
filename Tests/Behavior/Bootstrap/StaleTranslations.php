<?php

declare(strict_types=1);

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\Assert;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchroniser;
use Sitegeist\LostInTranslation\Domain\Retranslator;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchroniser;

trait StaleTranslations
{
    /**
     * @Then /^I expect exactly the following stale translations:$/
     * @param TableNode $payloadTable
     * @throws \Exception
     */
    public function IExpectExactlyTheFollowingStaleTranslations(TableNode $payloadTable): void
    {
        $staleTranslationFinder = $this->contentRepositoryRegistry->get($this->currentContentRepository->id)
            ->projectionState(StaleTranslationReadModel::class)
            ->staleTranslationFinder;

        $expectedStaleTranslations = $payloadTable->getColumnsHash();

        $actualStaleTranslations = array_map(
            fn (StaleTranslation $staleTranslation): array => [
                'workspaceName' => $staleTranslation->workspaceName->value,
                'originDimensionSpacePoint' => $staleTranslation->originDimensionSpacePoint->toJson(),
                'nodeAggregateId' => $staleTranslation->nodeAggregateId->value,
                'propertyNames' => \json_encode($staleTranslation->propertyNames),
            ],
            iterator_to_array($staleTranslationFinder->findAll()),
        );

        Assert::assertEquals($expectedStaleTranslations, $actualStaleTranslations);
    }

    /**
     * @When /^I retranslate node "([^"]*)" in workspace "([^"]*)" and dimension space point (.*)$/
     * @throws Exception
     */
    public function iRetranslateNode(string $nodeAggregateId, string $workspaceName, string $dimensionSpacePoint): void
    {
        $this->getObject(Retranslator::class)->retranslateNode(
            contentRepositoryId: $this->currentContentRepository->id,
            workspaceName: WorkspaceName::fromString($workspaceName),
            nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
        );
    }

    /**
     * @When /^I synchronise translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\})$/
     * @throws Exception
     */
    public function iSynchroniseTranslations(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
    ): void {
        $this->getObject(WorkspaceSynchroniser::class)->synchroniseWorkspace(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
        );
    }

    /**
     * @When /^I full-synchronise translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\})( including existing variants)?$/
     * @throws Exception
     */
    public function iFullSynchroniseTranslations(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $includingExisting = '',
    ): void {
        $skipExisting = $includingExisting === '';
        $this->getObject(FullWorkspaceSynchroniser::class)->synchroniseWorkspaceFull(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
            skipExisting: $skipExisting,
        );
    }

    /**
     * @template T ob object
     * @param class-string<T> $className
     * @return T
     */
    abstract protected function getObject(string $className): object;
}
