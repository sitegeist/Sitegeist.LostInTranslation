<?php

declare(strict_types=1);

use Behat\Gherkin\Node\TableNode;
use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use PHPUnit\Framework\Assert;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslation;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Neos\Neos\Domain\Repository\WorkspaceMetadataAndRoleRepository;
use Neos\Neos\Domain\Service\WorkspacePublishingService;
use Neos\Neos\Domain\Service\WorkspaceService;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchronizer;
use Sitegeist\LostInTranslation\Domain\Retranslator;
use Sitegeist\LostInTranslation\Domain\SourceRemovalBehavior;
use Sitegeist\LostInTranslation\Domain\SourceTaggingBehavior;
use Sitegeist\LostInTranslation\Domain\StaleTranslationProjectionStatusProvider;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationScope;
use Sitegeist\LostInTranslation\Domain\SynchronizationStatusProvider;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizationResult;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer;

trait StaleTranslations
{
    /**
     * Set by {@see self::theStaleTranslationProjectionSchemaIsRemoved()} so {@see self::recreateStaleTranslationProjectionSchema()}
     * can heal the dropped tables after the scenario.
     */
    private bool $staleTranslationSchemaWasRemoved = false;

    /**
     * The result of the most recent manual synchronization step, so a following `Then` can assert its reported counts.
     */
    private ?WorkspaceSynchronizationResult $lastSynchronizationResult = null;

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
        $this->getObject(Retranslator::class)->retranslateSubtree(
            contentRepositoryId: $this->currentContentRepository->id,
            workspaceName: WorkspaceName::fromString($workspaceName),
            nodeAggregateId: NodeAggregateId::fromString($nodeAggregateId),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($dimensionSpacePoint),
        );
    }

    /**
     * Shared runner for the manual ("sync now") {@see WorkspaceSynchronizer} steps below. Stores the result in
     * {@see self::$lastSynchronizationResult} so a following `Then` can assert the reported counts, and fails loudly if
     * the synchronizer short-circuited via `skipped(...)` (e.g. a mis-configured source/target).
     */
    private function runManualSynchronization(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        bool $dryRun,
        SourceRemovalBehavior $onSourceRemoval,
        SynchronizationScope $removalScope,
        SourceTaggingBehavior $onSourceTagging,
    ): void {
        $this->lastSynchronizationResult = $this->getObject(WorkspaceSynchronizer::class)->synchronizeWorkspace(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
            dryRun: $dryRun,
            onSourceRemoval: $onSourceRemoval,
            removalScope: $removalScope,
            onSourceTagging: $onSourceTagging,
        );
        Assert::assertNull(
            $this->lastSynchronizationResult->skippedReason,
            sprintf('WorkspaceSynchronizer skipped synchronization: %s', $this->lastSynchronizationResult->skippedReason ?? ''),
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
        $this->runManualSynchronization(
            $sourceWorkspaceName,
            $sourceDimensionSpacePoint,
            $targetWorkspaceName,
            $targetDimensionSpacePoint,
            dryRun: false,
            onSourceRemoval: SourceRemovalBehavior::KeepTarget,
            removalScope: SynchronizationScope::Document,
            onSourceTagging: SourceTaggingBehavior::KeepTarget,
        );
    }

    /**
     * Like {@see self::iSynchronizeTranslations()} but also mirrors source-language deletions: target-dimension nodes
     * whose source variant no longer exists are removed, gated by the given scope (`Content` keeps Documents, `Document`
     * removes them too). Exercises the manual / "sync now" diff path of {@see SourceRemovalBehavior::RemoveTarget}.
     *
     * @When /^I synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) removing orphans with scope "([^"]*)"$/
     * @throws Exception
     */
    public function iSynchronizeTranslationsRemovingOrphans(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $removalScope,
    ): void {
        $this->runManualSynchronization(
            $sourceWorkspaceName,
            $sourceDimensionSpacePoint,
            $targetWorkspaceName,
            $targetDimensionSpacePoint,
            dryRun: false,
            onSourceRemoval: SourceRemovalBehavior::RemoveTarget,
            removalScope: SynchronizationScope::from($removalScope),
            onSourceTagging: SourceTaggingBehavior::KeepTarget,
        );
    }

    /**
     * Like {@see self::iSynchronizeTranslations()} but also reconciles subtree tags: converges each target-dimension
     * node's explicit tags (hide/show and any other tag) onto the source. Exercises the manual / "sync now" diff path
     * of {@see SourceTaggingBehavior::SyncToTarget} ({@see \Sitegeist\LostInTranslation\Domain\TargetTagReconciler}).
     *
     * @When /^I synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) syncing subtree tags$/
     * @throws Exception
     */
    public function iSynchronizeTranslationsSyncingTags(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
    ): void {
        $this->runManualSynchronization(
            $sourceWorkspaceName,
            $sourceDimensionSpacePoint,
            $targetWorkspaceName,
            $targetDimensionSpacePoint,
            dryRun: false,
            onSourceRemoval: SourceRemovalBehavior::KeepTarget,
            removalScope: SynchronizationScope::Document,
            onSourceTagging: SourceTaggingBehavior::SyncToTarget,
        );
    }

    /**
     * Manual sync that mirrors BOTH source-language deletions and subtree-tag changes in one run.
     *
     * @When /^I synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) removing orphans with scope "([^"]*)" and syncing subtree tags$/
     * @throws Exception
     */
    public function iSynchronizeTranslationsRemovingOrphansAndSyncingTags(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $removalScope,
    ): void {
        $this->runManualSynchronization(
            $sourceWorkspaceName,
            $sourceDimensionSpacePoint,
            $targetWorkspaceName,
            $targetDimensionSpacePoint,
            dryRun: false,
            onSourceRemoval: SourceRemovalBehavior::RemoveTarget,
            removalScope: SynchronizationScope::from($removalScope),
            onSourceTagging: SourceTaggingBehavior::SyncToTarget,
        );
    }

    /**
     * Dry-run variant of {@see self::iSynchronizeTranslationsRemovingOrphansAndSyncingTags()}: the result reports what
     * WOULD be removed/tagged, but nothing is dispatched (target nodes stay untouched).
     *
     * @When /^I dry-run synchronize translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) removing orphans with scope "([^"]*)" and syncing subtree tags$/
     * @throws Exception
     */
    public function iDryRunSynchronizeTranslationsRemovingOrphansAndSyncingTags(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $removalScope,
    ): void {
        $this->runManualSynchronization(
            $sourceWorkspaceName,
            $sourceDimensionSpacePoint,
            $targetWorkspaceName,
            $targetDimensionSpacePoint,
            dryRun: true,
            onSourceRemoval: SourceRemovalBehavior::RemoveTarget,
            removalScope: SynchronizationScope::from($removalScope),
            onSourceTagging: SourceTaggingBehavior::SyncToTarget,
        );
    }

    /**
     * @Then /^the last synchronization reported (\d+) removal\(s\) and (\d+) tag change\(s\)$/
     * @throws Exception
     */
    public function theLastSynchronizationReportedCounts(string $expectedRemovals, string $expectedTagChanges): void
    {
        Assert::assertNotNull($this->lastSynchronizationResult, 'No synchronization has run yet');
        Assert::assertSame(
            (int)$expectedRemovals,
            $this->lastSynchronizationResult->totalRemovalCommandsDispatched(),
            'reported removal count',
        );
        Assert::assertSame(
            (int)$expectedTagChanges,
            $this->lastSynchronizationResult->totalTagCommandsDispatched(),
            'reported tag-change count',
        );
    }

    /**
     * Assert that a (stale-driven) synchronization gracefully short-circuits via `skipped(...)` instead of running —
     * e.g. because the target workspace does not exist or is not based on the source workspace. The skip reason must
     * contain the given fragment, mirroring the error the CLI / Neos UI surfaces to the user.
     *
     * @Then /^synchronizing translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) is skipped because of "([^"]*)"$/
     * @throws Exception
     */
    public function synchronizingIsSkippedBecauseOf(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $expectedReasonFragment,
    ): void {
        $result = $this->getObject(WorkspaceSynchronizer::class)->synchronizeWorkspace(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
        );
        Assert::assertNotNull($result->skippedReason, 'Expected synchronization to be skipped, but it ran');
        Assert::assertStringContainsString($expectedReasonFragment, $result->skippedReason);
    }

    /**
     * Same as {@see self::synchronizingIsSkippedBecauseOf()} for the full synchronizer (`synchronize --full`).
     *
     * @Then /^full-synchronizing translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) is skipped because of "([^"]*)"$/
     * @throws Exception
     */
    public function fullSynchronizingIsSkippedBecauseOf(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        string $expectedReasonFragment,
    ): void {
        $result = $this->getObject(FullWorkspaceSynchronizer::class)->synchronizeWorkspaceFull(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
        );
        Assert::assertNotNull($result->skippedReason, 'Expected full synchronization to be skipped, but it ran');
        Assert::assertStringContainsString($expectedReasonFragment, $result->skippedReason);
    }

    /**
     * Drop the recorded stale-translation rows for a single node aggregate, simulating the live data hole where the
     * stale-translation projection was installed AFTER the node was created (so it never got a row), while nodes added
     * later did. Used to exercise the stale-driven sync's graceful skip when an ancestor document has no stale row.
     *
     * @When /^I remove the recorded stale translations for node "([^"]*)" in workspace "([^"]*)"$/
     * @throws Exception
     */
    public function iRemoveTheRecordedStaleTranslationsForNode(string $nodeAggregateId, string $workspaceName): void
    {
        $this->contentRepositoryRegistry->get($this->currentContentRepository->id)
            ->projectionState(StaleTranslationReadModel::class)
            ->staleTranslationMaintenance
            ->removeStaleRowsForNodeAggregate(
                WorkspaceName::fromString($workspaceName),
                NodeAggregateId::fromString($nodeAggregateId),
            );
    }

    /**
     * Assert that a stale-driven synchronization ran (did not short-circuit) but gracefully skipped a number of nodes
     * it could not bootstrap because an ancestor document is missing in the target and has no stale row. Every such
     * skip must carry the `--full` hint, mirroring what the CLI / backend module surface to the user.
     *
     * @Then /^synchronizing translations from workspace "([^"]*)" dimension space point (\{[^}]+\}) to workspace "([^"]*)" dimension space point (\{[^}]+\}) skips (\d+) node\(s\) pending a full sync$/
     * @throws Exception
     */
    public function synchronizingSkipsNodesPendingAFullSync(
        string $sourceWorkspaceName,
        string $sourceDimensionSpacePoint,
        string $targetWorkspaceName,
        string $targetDimensionSpacePoint,
        int $expectedCount,
    ): void {
        $result = $this->getObject(WorkspaceSynchronizer::class)->synchronizeWorkspace(
            contentRepositoryId: $this->currentContentRepository->id,
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspaceName),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromJsonString($sourceDimensionSpacePoint),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspaceName),
            targetDimensionSpacePoint: DimensionSpacePoint::fromJsonString($targetDimensionSpacePoint),
        );
        Assert::assertNull(
            $result->skippedReason,
            sprintf('Expected the run to proceed and skip individual nodes, but it short-circuited: %s', $result->skippedReason ?? ''),
        );
        Assert::assertSame(
            $expectedCount,
            $result->totalNodesRequiringFullSync(),
            'Unexpected number of nodes skipped pending a full sync',
        );
        foreach ($result->perNodeResults as $perNode) {
            if ($perNode->result->requiresFullSync) {
                Assert::assertStringContainsString('--full', (string)$perNode->result->skippedReason);
            }
        }
    }

    /**
     * Assert the backend module's "out of sync" count for a synchronization rule (source → target). The count is
     * computed by {@see SynchronizationStatusProvider::pendingCountForRule()} and must reflect the TARGET workspace's
     * stale rows. Scope/mode do not affect the count, so we use defaults here.
     *
     * @Then /^the out-of-sync count from workspace "([^"]*)" dimension "([^"]*)" to workspace "([^"]*)" dimension "([^"]*)" is (\d+)$/
     * @throws Exception
     */
    public function theOutOfSyncCountIs(
        string $sourceWorkspaceName,
        string $sourceDimension,
        string $targetWorkspaceName,
        string $targetDimension,
        int $expectedCount,
    ): void {
        $rule = new SynchronizationRule(
            sourceWorkspaceName: $sourceWorkspaceName,
            sourceDimension: $sourceDimension,
            targetWorkspaceName: $targetWorkspaceName,
            targetDimension: $targetDimension,
            scope: SynchronizationScope::Content,
        );
        $actual = $this->getObject(SynchronizationStatusProvider::class)->pendingCountForRule(
            $this->currentContentRepository->id,
            $rule,
        );
        Assert::assertSame($expectedCount, $actual, sprintf(
            'Expected out-of-sync count %d for %s/%s -> %s/%s, got %d',
            $expectedCount,
            $sourceWorkspaceName,
            $sourceDimension,
            $targetWorkspaceName,
            $targetDimension,
            $actual,
        ));
    }

    /**
     * @Then /^I expect workspace "([^"]*)" to not exist$/
     * @throws Exception
     */
    public function iExpectWorkspaceToNotExist(string $workspaceName): void
    {
        $cr = $this->contentRepositoryRegistry->get($this->currentContentRepository->id);
        Assert::assertNull(
            $cr->findWorkspaceByName(WorkspaceName::fromString($workspaceName)),
            sprintf('Workspace "%s" unexpectedly exists', $workspaceName),
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
     * Assert a node carries (or does not carry) a given subtree tag in a (workspace, dimension) subgraph by workspace
     * NAME. Read `withoutRestrictions` so a disabled node is still visible (the default constraints would filter it
     * out). `$negate` is the " not" group from the regex — present means assert the tag is absent.
     *
     * @When /^I expect node "([^"]*)" in workspace "([^"]*)" dimension space point (\{[^}]+\}) to( not)? be tagged "([^"]*)"$/
     * @throws Exception
     */
    public function iExpectNodeToBeTagged(
        string $nodeAggregateId,
        string $workspaceName,
        string $dimensionSpacePoint,
        string $negate,
        string $tag,
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
        $hasTag = $node->tags->contain(SubtreeTag::fromString($tag));
        if ($negate !== '') {
            Assert::assertFalse(
                $hasTag,
                sprintf('Node "%s" in %s@%s is unexpectedly tagged "%s"', $nodeAggregateId, $dimensionSpacePoint, $workspaceName, $tag),
            );
            return;
        }
        Assert::assertTrue(
            $hasTag,
            sprintf('Node "%s" in %s@%s is not tagged "%s"', $nodeAggregateId, $dimensionSpacePoint, $workspaceName, $tag),
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
     * Drop the stale-translation projection's tables to simulate a content repository where `./flow cr:setup` has not
     * created (or has lost) the projection schema. The subscription row still exists and claims to be ACTIVE, so the
     * content repository recomputes the setup status against the live schema and reports SETUP_REQUIRED — exactly the
     * state the backend module must surface instead of faulting.
     *
     * NOT self-healing on its own: the testsuite's {@see CRBehavioralTestsSubjectProvider::setUpContentRepository()}
     * runs `ContentRepositoryMaintainer::setUp()` only ONCE per CR id per process (static `$alreadySetUpContentRepositories`
     * guard); later scenarios merely truncate the event table and reset projection state, so a dropped table stays
     * dropped and every following scenario faults with "Table cr_default_p_staletranslation doesn't exist". We therefore
     * recreate the schema in {@see self::recreateStaleTranslationProjectionSchema()} after the scenario.
     *
     * @When /^the stale-translation projection schema is removed$/
     */
    public function theStaleTranslationProjectionSchemaIsRemoved(): void
    {
        $connection = $this->getObject(EntityManagerInterface::class)->getConnection();
        $tableNamePrefix = sprintf('cr_%s_p_staletranslation', $this->currentContentRepository->id->value);
        foreach (['_nodeaggregate_type', '_ws_hierarchy', ''] as $tableNameSuffix) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $tableNamePrefix . $tableNameSuffix);
        }
        $this->staleTranslationSchemaWasRemoved = true;
    }

    /**
     * Heal a scenario that dropped the stale-translation projection schema (see
     * {@see self::theStaleTranslationProjectionSchemaIsRemoved()}): re-run the content repository maintainer's idempotent
     * `setUp()`, which recreates the missing projection tables. Without this, every subsequent scenario in the same
     * behat run faults on the missing table, because the testsuite only sets a CR up once per process.
     *
     * @AfterScenario
     * @throws Exception
     */
    public function recreateStaleTranslationProjectionSchema(): void
    {
        if (!$this->staleTranslationSchemaWasRemoved) {
            return;
        }
        $this->staleTranslationSchemaWasRemoved = false;
        $maintainer = $this->contentRepositoryRegistry->buildService(
            $this->currentContentRepository->id,
            new ContentRepositoryMaintainerFactory(),
        );
        $result = $maintainer->setUp();
        Assert::assertNull($result, sprintf('Failed to recreate stale-translation projection schema: %s', $result?->getMessage() ?? ''));
    }

    /**
     * Assert how {@see StaleTranslationProjectionStatusProvider} — the source the backend module reads to decide whether
     * to show the synchronization overview or a setup banner — reports the projection. `$readiness` is "ready" or
     * "not set up".
     *
     * @Then /^the stale-translation projection is reported as "(ready|not set up)"$/
     */
    public function theStaleTranslationProjectionIsReportedAs(string $readiness): void
    {
        $status = $this->getObject(StaleTranslationProjectionStatusProvider::class)
            ->forContentRepository($this->currentContentRepository->id);
        Assert::assertSame(
            $readiness === 'ready',
            $status->isReady,
            sprintf('Unexpected projection readiness; provider reported: %s', $status->summary),
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
