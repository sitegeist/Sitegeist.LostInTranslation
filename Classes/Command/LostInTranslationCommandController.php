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
use Sitegeist\LostInTranslation\Domain\Retranslator;

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
     * Thin shell-friendly wrapper around {@see Retranslator::retranslateNode()}. Exists so the same
     * driver that powers the Behat `iRetranslateNode` step can also be exercised manually against a
     * real DeepL key in a Neos distribution. Keeps all real logic in `Retranslator` — this method
     * intentionally adds no behaviour beyond argument parsing and a confirmation line.
     *
     * `$target` is the **target** dimension value (where translations land). The source is derived
     * from the target preset's `referenceLanguage` option by the Retranslator itself.
     *
     * @param string $nodeAggregateId
     * @param string $target
     * @param string $contentRepository
     * @param string $workspace
     * @return void
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

        // Tell the operator what actually happened. The previous version printed "finished" even on
        // a silent no-op skip path (e.g. invoking retranslate on the source language itself, or with
        // DeepL disabled for one of the presets), which made misconfiguration invisible from the CLI.
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
}
