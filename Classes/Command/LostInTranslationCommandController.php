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
use Sitegeist\LostInTranslation\Domain\PerNodeSynchronisationResult;
use Sitegeist\LostInTranslation\Domain\Retranslator;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchroniser;

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
    public WorkspaceSynchroniser $workspaceSynchroniser;

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
     * Synchronise every stale translation in the target workspace+dimension by dispatching one
     * retranslation per stale-translation record. Source workspace+dimension are accepted but
     * must currently equal the target workspace and the configured `referenceLanguage` of the
     * target dimension respectively (cross-workspace sync is not yet supported).
     *
     * @param string $sourceWorkspace Source workspace name. Must equal --target-workspace for now.
     * @param string $sourceDimension Source language dimension value. Must equal the configured `referenceLanguage` of --target-dimension.
     * @param string $targetWorkspace Target workspace whose stale records will be processed.
     * @param string $targetDimension Target language dimension value (e.g. "de").
     * @param string $contentRepository Content repository id (defaults to "default").
     * @param bool $dryRun If set, report which stale records would be processed without dispatching any commands.
     * @throws StopCommandException
     */
    public function synchroniseCommand(
        string $sourceWorkspace,
        string $sourceDimension,
        string $targetWorkspace,
        string $targetDimension,
        string $contentRepository = 'default',
        bool $dryRun = false,
    ): void {
        $result = $this->workspaceSynchroniser->synchroniseWorkspace(
            contentRepositoryId: ContentRepositoryId::fromString($contentRepository),
            sourceWorkspaceName: WorkspaceName::fromString($sourceWorkspace),
            sourceDimensionSpacePoint: DimensionSpacePoint::fromArray([$this->languageDimensionName => $sourceDimension]),
            targetWorkspaceName: WorkspaceName::fromString($targetWorkspace),
            targetDimensionSpacePoint: DimensionSpacePoint::fromArray([$this->languageDimensionName => $targetDimension]),
            dryRun: $dryRun,
        );

        if ($result->skippedReason !== null) {
            $this->outputLine('Synchronisation skipped: %s', [$result->skippedReason]);
            $this->quit(1);
        }

        if ($result->perNodeResults === []) {
            $this->outputLine('No stale translations to synchronise for workspace "%s" / dimension "%s".', [$targetWorkspace, $targetDimension]);
            return;
        }

        foreach ($result->perNodeResults as $perNode) {
            $this->outputLine($this->formatPerNodeLine($perNode, $dryRun));
        }
        $this->outputLine(
            '%s: %d node(s) processed, %d stale property update(s) and %d variant creation(s) dispatched, %d skipped.',
            [
                $dryRun ? 'Dry run' : 'Synchronisation finished',
                count($result->perNodeResults),
                $result->totalStalePropertyCommandsDispatched(),
                $result->totalVariantCommandsDispatched(),
                $result->totalSkippedNodes(),
            ],
        );
    }

    private function formatPerNodeLine(PerNodeSynchronisationResult $perNode, bool $dryRun): string
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
