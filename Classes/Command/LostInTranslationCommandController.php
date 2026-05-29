<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Command;

use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Feature\Security\Exception\AccessDenied;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Security\Context;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchronizer;
use Sitegeist\LostInTranslation\Domain\PerNodeSynchronizationResult;
use Sitegeist\LostInTranslation\Domain\Retranslator;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer;

class LostInTranslationCommandController extends CommandController
{
    #[Flow\InjectConfiguration(path:'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    #[Flow\Inject]
    public ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    public Context $securityContext;

    #[Flow\Inject]
    public Retranslator $retranslator;

    #[Flow\Inject]
    public WorkspaceSynchronizer $workspaceSynchronizer;

    #[Flow\Inject]
    public FullWorkspaceSynchronizer $fullWorkspaceSynchronizer;

    /**
     * This command recursively copies content from the source to the target language dimension within the specified repository, workspace, and node path.
     *
     * @param string $source
     * @param string $target
     * @param string $contentRepository
     * @param string $workspace
     * @param string $nodePath
     * @return void
     * @throws AccessDenied
     * @throws StopCommandException
     */
    public function translateCommand(string $source, string $target, string $contentRepository = 'default', string $workspace = 'live', string $nodePath = '/<Neos.Neos:Sites>'): void
    {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

            $workspaceName = WorkspaceName::fromString($workspace);

        if ($cr->findWorkspaceByName($workspaceName) === null) {
            $this->outputLine("workspace not fround");
            $this->quit(1);
        }

            $graph = $cr->getContentGraph($workspaceName);
            $originSubgraph = $graph->getSubgraph(DimensionSpacePoint::fromArray([$this->languageDimensionName => $source]), VisibilityConstraints::withoutRestrictions());
            $targetSubgraph = $graph->getSubgraph(DimensionSpacePoint::fromArray([$this->languageDimensionName => $target]), VisibilityConstraints::withoutRestrictions());

            $start = $originSubgraph->findNodeByAbsolutePath(AbsoluteNodePath::fromString($nodePath));

        if ($start === null) {
            $this->outputLine("No node found for path {$nodePath}");
            $this->quit(1);
        }
            $this->translateNodeRecursive($cr, $start, $originSubgraph, $targetSubgraph);
    }

    public function translateNodeRecursive(ContentRepository $cr, Node $originNode, ContentSubgraphInterface $originSubgraph, ContentSubgraphInterface $targetSubgraph): void
    {
        $targetNode = $targetSubgraph->findNodeById($originNode->aggregateId);
        if ($targetNode === null) {
            $cr->handle(CreateNodeVariant::create(
                $originSubgraph->getWorkspaceName(),
                $originNode->aggregateId,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($originNode->dimensionSpacePoint),
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint())
            ));
        }
        foreach ($originSubgraph->findChildNodes($originNode->aggregateId, FindChildNodesFilter::create())->getIterator() as $childNode) {
            $this->translateNodeRecursive($cr, $childNode, $originSubgraph, $targetSubgraph);
        }
    }

    /**
     * Retranslate (stale properties + missing variants) below the given node into the target language dimension.
     *
     * `$target` is the target dimension value; the source is derived from its `referenceLanguage` preset.
     */
    public function retranslateNodeCommand(
        string $nodeAggregateId,
        string $target,
        string $contentRepository,
        string $workspace,
    ): void {
        $this->outputLine('Starting retranslation for node "%s" -> "%s" in workspace "%s"...', [$nodeAggregateId, $target, $workspace]);
        $result = $this->retranslator->retranslateNode(
            ContentRepositoryId::fromString($contentRepository),
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $target]),
        );

        // Distinct messages for skip / no-op / dispatched so misconfiguration is visible from CLI.
        if ($result->skippedReason !== null) {
            $this->outputLine('Retranslation for node "%s" -> "%s" skipped: %s', [$nodeAggregateId, $target, $result->skippedReason]);
            return;
        }
        if ($result->isNoOp()) {
            $this->outputLine('Retranslation for node "%s" -> "%s": nothing to do (no stale properties, no missing variants).', [$nodeAggregateId, $target]);
            return;
        }
        $this->outputLine(
            'Retranslation for node "%s" -> "%s": dispatched %d stale property update(s) and %d variant creation(s).',
            [$nodeAggregateId, $target, $result->stalePropertyCommandsDispatched, $result->variantCommandsDispatched],
        );
    }

    /**
     * Synchronize translations in the target workspace+dimension. Two modes:
     *
     *  - **default (stale-driven)**: dispatches one retranslation per record the projection has flagged stale at
     *    `(targetWorkspace, targetDimension)`.
     *  - **`--full`**: walks the entire source-dimension subgraph from every root aggregate down and considers every
     *    translatable node, regardless of stale state. Nodes whose target variant already exists and have no stale
     *    row are kept untouched (manual edits are preserved).
     *
     * Source and target workspace may differ (cross-workspace sync): the source content is read from
     * --source-workspace while the resulting variant/property commands are dispatched into --target-workspace, e.g.
     * preparing a `de` translation of published `live` content inside a forked `de-review` workspace. In that case the
     * target workspace is first force-rebased onto its base (which must be the source workspace) so its source
     * dimension is current and holds every source node; conflicting target-side changes are dropped, non-conflicting
     * review edits are kept. --source-dimension must still equal the configured `referenceLanguage` of
     * --target-dimension.
     *
     * @param string $sourceWorkspace Source workspace name (content is read from here; may differ from --target-workspace).
     * @param string $sourceDimension Source language dimension value. Must equal the configured `referenceLanguage` of
     *                                --target-dimension.
     * @param string $targetWorkspace Target workspace the translation commands are dispatched into.
     * @param string $targetDimension Target language dimension value (e.g. "de").
     * @param string $contentRepository Content repository id (defaults to "default").
     * @param bool $dryRun If set, report which records/nodes would be processed without dispatching any commands.
     * @param bool $full If set, run full-workspace sync instead of the stale-driven default.
     * @throws StopCommandException
     */
    public function synchronizeCommand(
        string $sourceWorkspace,
        string $sourceDimension,
        string $targetWorkspace,
        string $targetDimension,
        string $contentRepository = 'default',
        bool $dryRun = false,
        bool $full = false,
    ): void {
        $contentRepositoryId = ContentRepositoryId::fromString($contentRepository);
        $sourceDsp = DimensionSpacePoint::fromArray([$this->languageDimensionName => $sourceDimension]);
        $targetDsp = DimensionSpacePoint::fromArray([$this->languageDimensionName => $targetDimension]);
        $sourceWorkspaceName = WorkspaceName::fromString($sourceWorkspace);
        $targetWorkspaceName = WorkspaceName::fromString($targetWorkspace);

        $result = $full
            ? $this->fullWorkspaceSynchronizer->synchronizeWorkspaceFull(
                contentRepositoryId: $contentRepositoryId,
                sourceWorkspaceName: $sourceWorkspaceName,
                sourceDimensionSpacePoint: $sourceDsp,
                targetWorkspaceName: $targetWorkspaceName,
                targetDimensionSpacePoint: $targetDsp,
                dryRun: $dryRun,
            )
            : $this->workspaceSynchronizer->synchronizeWorkspace(
                contentRepositoryId: $contentRepositoryId,
                sourceWorkspaceName: $sourceWorkspaceName,
                sourceDimensionSpacePoint: $sourceDsp,
                targetWorkspaceName: $targetWorkspaceName,
                targetDimensionSpacePoint: $targetDsp,
                dryRun: $dryRun,
            );

        if ($result->skippedReason !== null) {
            $this->outputLine('Synchronization skipped: %s', [$result->skippedReason]);
            $this->quit(1);
        }

        if ($result->perNodeResults === []) {
            $this->outputLine('No stale translations to synchronize for workspace "%s" / dimension "%s".', [$targetWorkspace, $targetDimension]);
            return;
        }

        foreach ($result->perNodeResults as $perNode) {
            $this->outputLine($this->formatPerNodeLine($perNode, $dryRun));
        }
        $this->outputLine(
            '%s: %d node(s) processed, %d stale property update(s) and %d variant creation(s) dispatched, %d skipped.',
            [
                $dryRun ? 'Dry run' : 'Synchronization finished',
                count($result->perNodeResults),
                $result->totalStalePropertyCommandsDispatched(),
                $result->totalVariantCommandsDispatched(),
                $result->totalSkippedNodes(),
            ],
        );
    }

    /**
     * Remove stale-translation rows whose node aggregate no longer exists in the ContentGraph for the
     * given workspace. The projection only cleans up the directly-removed aggregate on
     * NodeAggregateWasRemoved; descendants (e.g. a Document's tethered content collection or nested
     * content nodes) cascade away in the ContentGraph but leave orphan rows behind here. Run this after
     * bulk deletes to prune them.
     *
     * @param string $workspace Workspace whose stale rows will be reconciled.
     * @param string $contentRepository Content repository id (defaults to "default").
     * @param bool $dryRun If set, report the orphans without DELETing anything.
     */
    public function reconcileCommand(
        string $workspace = 'live',
        string $contentRepository = 'default',
        bool $dryRun = false,
    ): void {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));
        $workspaceName = WorkspaceName::fromString($workspace);
        if ($cr->findWorkspaceByName($workspaceName) === null) {
            $this->outputLine('Workspace "%s" not found in content repository "%s".', [$workspace, $contentRepository]);
            $this->quit(1);
        }
        $contentGraph = $cr->getContentGraph($workspaceName);
        $readModel = $cr->projectionState(StaleTranslationReadModel::class);

        // Aggregate orphans across all (workspace, nodeAggregateId) — a single aggregate can have multiple
        // stale rows (one per target dimension) and a single DELETE drops them all at once.
        /** @var array<string, NodeAggregateId> $orphans */
        $orphans = [];
        foreach ($readModel->staleTranslationFinder->findAll() as $stale) {
            if (!$stale->workspaceName->equals($workspaceName)) {
                continue;
            }
            if (isset($orphans[$stale->nodeAggregateId->value])) {
                continue;
            }
            if ($contentGraph->findNodeAggregateById($stale->nodeAggregateId) === null) {
                $orphans[$stale->nodeAggregateId->value] = $stale->nodeAggregateId;
            }
        }

        if ($orphans === []) {
            $this->outputLine('No orphaned stale-translation rows in workspace "%s".', [$workspace]);
            return;
        }

        $deletedRows = 0;
        foreach ($orphans as $nodeAggregateId) {
            if ($dryRun) {
                $this->outputLine('  - %s: would prune', [$nodeAggregateId->value]);
                continue;
            }
            $deletedRows += $readModel->staleTranslationMaintenance->removeStaleRowsForNodeAggregate($workspaceName, $nodeAggregateId);
            $this->outputLine('  - %s: pruned', [$nodeAggregateId->value]);
        }

        if ($dryRun) {
            $this->outputLine('Dry run: %d orphaned node aggregate(s) in workspace "%s" would be pruned.', [count($orphans), $workspace]);
            return;
        }
        $this->outputLine('Pruned %d row(s) for %d orphaned node aggregate(s) in workspace "%s".', [$deletedRows, count($orphans), $workspace]);
    }

    private function formatPerNodeLine(PerNodeSynchronizationResult $perNode, bool $dryRun): string
    {
        $r = $perNode->result;
        if ($r->skippedReason !== null) {
            return sprintf('  - %s: skipped (%s)', $perNode->nodeAggregateId->value, $r->skippedReason);
        }
        if ($r->isNoOp()) {
            return sprintf('  - %s: no-op (already in sync)', $perNode->nodeAggregateId->value);
        }
        return sprintf(
            '  - %s: %s%d stale property update(s), %d variant creation(s)',
            $perNode->nodeAggregateId->value,
            $dryRun ? 'would dispatch ' : 'dispatched ',
            $r->stalePropertyCommandsDispatched,
            $r->variantCommandsDispatched,
        );
    }
}
