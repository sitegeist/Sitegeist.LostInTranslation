<?php

declare(strict_types=1);

use Behat\Gherkin\Node\TableNode;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use PHPUnit\Framework\Assert;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchronizer;
use Sitegeist\LostInTranslation\Domain\Retranslator;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer;

trait StaleTranslations
{
    /**
     * Workspace metadata and role assignments live in the `neos_neos_workspace_metadata` / `neos_neos_workspace_role`
     * tables, which Neos lists under `ignoredTables` so they are NOT truncated by the `@flowEntities` reset (the same
     * way `cr_*` tables are preserved). Prune them per scenario so an auto-created shared review workspace from one
     * scenario (or a previous suite run) cannot collide with the next via a duplicate-metadata insert.
     *
     * @BeforeScenario
     */
    public function pruneWorkspaceMetadataAndRoles(): void
    {
        $repository = $this->getObject(WorkspaceMetadataAndRoleRepository::class);
        $contentRepositoryId = ContentRepositoryId::fromString('default');
        $repository->pruneWorkspaceMetadata($contentRepositoryId);
        $repository->pruneRoleAssignments($contentRepositoryId);
    }

    /**
     * Assert that a workspace exists with the given base workspace and Neos classification (PERSONAL/SHARED/ROOT).
     * Existence and base are read from the ContentRepository; the classification comes from the Neos workspace
     * metadata written by {@see WorkspaceService::createSharedWorkspace()}.
     *
     * @Then /^I expect workspace "([^"]*)" to exist with base workspace "([^"]*)" and classification "([^"]*)"$/
     * @throws Exception
     */
    public function iExpectWorkspaceToExistWithBaseAndClassification(
        string $workspaceName,
        string $baseWorkspaceName,
        string $classification,
    ): void {
        $contentRepositoryId = $this->currentContentRepository->id;
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $workspace = $cr->findWorkspaceByName(WorkspaceName::fromString($workspaceName));
        Assert::assertNotNull($workspace, sprintf('Workspace "%s" does not exist', $workspaceName));
        Assert::assertSame(
            $baseWorkspaceName,
            $workspace->baseWorkspaceName?->value,
            sprintf('Workspace "%s" has unexpected base workspace', $workspaceName),
        );
        $metadata = $this->getObject(WorkspaceService::class)->getWorkspaceMetadata(
            $contentRepositoryId,
            WorkspaceName::fromString($workspaceName),
        );
        Assert::assertSame(
            $classification,
            $metadata->classification->value,
            sprintf('Workspace "%s" has unexpected classification', $workspaceName),
        );
    }

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
     * @When /^I synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\})$/
     * @throws Exception
     */
    public function iSynchronizeTranslations(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
    ): void {
        $result = $this->getObject(WorkspaceSynchronizer::class)->synchronizeWorkspace(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
        );
        // Fail loudly if the synchronizer short-circuited via `skipped(...)` (e.g. mis-configured source/target).
        // Without this a scenario that meant to exercise sync but mis-typed a dimension would silently pass.
        Assert::assertNull(
            $result->skippedReason,
            sprintf('WorkspaceSynchronizer skipped synchronization: %s', $result->skippedReason ?? ''),
        );
    }

    /**
     * @When /^I full-synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\})( including existing variants)?$/
     * @throws Exception
     */
    public function iFullSynchronizeTranslations(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $includingExisting = '',
    ): void {
        $skipExisting = $includingExisting === '';
        $result = $this->getObject(FullWorkspaceSynchronizer::class)->synchronizeWorkspaceFull(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
            skipExisting: $skipExisting,
        );
        Assert::assertNull(
            $result->skippedReason,
            sprintf('FullWorkspaceSynchronizer skipped synchronization: %s', $result->skippedReason ?? ''),
        );
    }

    /**
     * Read a node's property directly from a (workspace, dimension) subgraph by workspace NAME — robust to content
     * stream id changes (e.g. after a cross-workspace sync force-rebases the target workspace, which mints a new
     * content stream). Use this instead of event-stream assertions when the target content stream id is not
     * deterministic.
     *
     * @When /^I expect node "([^"]*)" in workspace "([^"]*)" dimension space point (\{[^}]+\}) to have property "([^"]*)" with value "([^"]*)"$/
     * @throws Exception
     */
    public function iExpectNodeToHaveProperty(
        string $nodeAggregateId,
        string $workspaceName,
        string $dimensionSpacePoint,
        string $propertyName,
        string $expectedValue,
    ): void {
        $cr = $this->contentRepositoryRegistry->get($this->currentContentRepository->id);
        $subgraph = $cr->getContentGraph(WorkspaceName::fromString($workspaceName))->getSubgraph(
            DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
            VisibilityConstraints::withoutRestrictions(),
        );
        $node = $subgraph->findNodeById(NodeAggregateId::fromString($nodeAggregateId));
        Assert::assertNotNull(
            $node,
            sprintf('Node "%s" not found in %s@%s', $nodeAggregateId, $dimensionSpacePoint, $workspaceName),
        );
        Assert::assertSame(
            $expectedValue,
            $node->getProperty(PropertyName::fromString($propertyName)),
            sprintf('Property "%s" of node "%s" in %s@%s does not match', $propertyName, $nodeAggregateId, $dimensionSpacePoint, $workspaceName),
        );
    }

    /**
     * Assert a node aggregate is entirely absent from a (workspace, dimension) subgraph by workspace NAME.
     *
     * @When /^I expect node "([^"]*)" to be absent in workspace "([^"]*)" dimension space point (\{[^}]+\})$/
     * @throws Exception
     */
    public function iExpectNodeToBeAbsent(
        string $nodeAggregateId,
        string $workspaceName,
        string $dimensionSpacePoint,
    ): void {
        $cr = $this->contentRepositoryRegistry->get($this->currentContentRepository->id);
        $subgraph = $cr->getContentGraph(WorkspaceName::fromString($workspaceName))->getSubgraph(
            DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
            VisibilityConstraints::withoutRestrictions(),
        );
        Assert::assertNull(
            $subgraph->findNodeById(NodeAggregateId::fromString($nodeAggregateId)),
            sprintf('Node "%s" unexpectedly present in %s@%s', $nodeAggregateId, $dimensionSpacePoint, $workspaceName),
        );
    }

    /**
     * Mirrors the `flow lostintranslation:reconcile` CLI: walk the stale-translation rows for the given workspace and
     * prune those whose node aggregate no longer exists in the ContentGraph (orphans left behind because the projection
     * does not cascade descendant cleanup on node removal).
     *
     * @When /^I reconcile stale translations in workspace "([^"]*)"$/
     * @throws Exception
     */
    public function iReconcileStaleTranslationsInWorkspace(string $workspaceName): void
    {
        $workspaceNameVo = WorkspaceName::fromString($workspaceName);
        $cr = $this->contentRepositoryRegistry->get($this->currentContentRepository->id);
        $contentGraph = $cr->getContentGraph($workspaceNameVo);
        $readModel = $cr->projectionState(StaleTranslationReadModel::class);

        $seen = [];
        foreach ($readModel->staleTranslationFinder->findAll() as $stale) {
            if (!$stale->workspaceName->equals($workspaceNameVo)) {
                continue;
            }
            if (isset($seen[$stale->nodeAggregateId->value])) {
                continue;
            }
            $seen[$stale->nodeAggregateId->value] = true;
            if ($contentGraph->findNodeAggregateById($stale->nodeAggregateId) === null) {
                $readModel->staleTranslationMaintenance->removeStaleRowsForNodeAggregate($workspaceNameVo, $stale->nodeAggregateId);
            }
        }
    }

    /**
     * Application-level "Publish" button on a document in the Neos UI — publishes the document itself together with the
     * content nodes below it, leaving sibling documents alone. Delegates to
     * {@see WorkspacePublishingService::publishChangesInDocument()} which under the hood emits a
     * {@see \Neos\ContentRepository\Core\Feature\WorkspacePublication\Command\PublishIndividualNodesFromWorkspace} for
     * the resolved document subtree.
     *
     * @When the command PublishChangesInDocument is executed with payload:
     * @throws \Exception
     */
    public function theCommandPublishChangesInDocumentIsExecutedWithPayload(TableNode $payloadTable): void
    {
        $payload = $this->readPayloadTable($payloadTable);
        $this->getObject(WorkspacePublishingService::class)->publishChangesInDocument(
            $this->currentContentRepository->id,
            WorkspaceName::fromString($payload['workspaceName']),
            NodeAggregateId::fromString($payload['documentId']),
        );
    }

    /**
     * @template T ob object
     * @param class-string<T> $className
     * @return T
     */
    abstract protected function getObject(string $className): object;

    /**
     * @return array<string,mixed>
     */
    abstract protected function readPayloadTable(TableNode $payloadTable): array;
}
