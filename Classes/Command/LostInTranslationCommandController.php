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
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Security\Context;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Sitegeist\LostInTranslation\Domain\Retranslator;

class LostInTranslationCommandController extends CommandController
{
    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
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
    public function translateCommand(
        string $source,
        string $target,
        string $contentRepository = 'default',
        string $workspace = 'live',
        string $nodePath = '/<Neos.Neos:Sites>'
    ): void {
        $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contentRepository));

        $workspaceName = WorkspaceName::fromString($workspace);

        if ($cr->findWorkspaceByName($workspaceName) === null) {
            $this->outputFormatted(
                "Workspace \"%s\" not found in content repository \"%s\"",
                [
                    $workspaceName->value,
                    $cr->id->value
                ]
            );
            $this->quit(1);
        }

        # Create array with the default coordinates for the content repository
        $contentDimensions = $cr->getContentDimensionSource()->getContentDimensionsOrderedByPriority();
        $defaultDimensionConfiguration = [];
        foreach ($contentDimensions as $contentDimension) {
            $defaultValue = array_first($contentDimension->getRootValues())?->value;
            if ($defaultValue !== null) {
                $defaultDimensionConfiguration[$contentDimension->id->value] = $defaultValue;
            }
        }

        # Try to parse source and target as JSON stringified dimension coordinate.
        # If that fails, assume they are language values and merge the default dimension configuration with the given value to make sure we have a valid DSP.
        try {
            $sourceDimensionSpacePoint = DimensionSpacePoint::fromJsonString($source);
        } catch (\TypeError) {
            $sourceDimensionSpacePoint = DimensionSpacePoint::fromArray([
                ...$defaultDimensionConfiguration,
                $this->languageDimensionName => $source
            ]);
        }
        try {
            $targetDimensionSpacePoint = DimensionSpacePoint::fromJsonString($target);
        } catch (\TypeError) {
            $targetDimensionSpacePoint = DimensionSpacePoint::fromArray([
                ...$defaultDimensionConfiguration,
                $this->languageDimensionName => $target
            ]);
        }

        $graph = $cr->getContentGraph($workspaceName);
        $originSubgraph = $graph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $graph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $start = $originSubgraph->findNodeByAbsolutePath(AbsoluteNodePath::fromString($nodePath));
        if ($start === null) {
            $this->outputLine("No node found for path {$nodePath}");
            $this->quit(1);
        }
        $createdCount = 0;
        $skippedCount = 0;
        $this->translateNodeRecursive($cr, $start, $originSubgraph, $targetSubgraph, $createdCount, $skippedCount);
        $this->outputFormatted('Created: %d, Skipped: %d', [$createdCount, $skippedCount]);
    }

    public function translateNodeRecursive(
        ContentRepository $cr,
        Node $originNode,
        ContentSubgraphInterface $originSubgraph,
        ContentSubgraphInterface $targetSubgraph,
        &$createdCount = 0,
        &$skippedCount = 0,
    ): void {
        $targetNode = $targetSubgraph->findNodeById($originNode->aggregateId);
        if ($targetNode === null || !$targetNode->originDimensionSpacePoint->equals($targetSubgraph->getDimensionSpacePoint())) {
            $cr->handle(CreateNodeVariant::create(
                $originSubgraph->getWorkspaceName(),
                $originNode->aggregateId,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($originNode->dimensionSpacePoint),
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetSubgraph->getDimensionSpacePoint())
            ));
            $createdCount++;
            $this->outputFormatted("<success>Created: New variant for node %s</success>", [$originNode->aggregateId->value]);
        } else {
            $skippedCount++;
            $this->outputFormatted("<info>Skipped: Node %s already has a variant in target workspace</info>", [$originNode->aggregateId->value]);
        }
        foreach (
            $originSubgraph->findChildNodes($originNode->aggregateId, FindChildNodesFilter::create())->getIterator(
            ) as $childNode
        ) {
            $this->translateNodeRecursive(
                $cr,
                $childNode,
                $originSubgraph,
                $targetSubgraph,
                $createdCount,
                $skippedCount
            );
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
        $this->outputLine(
            'Starting retranslation for node "%s" -> "%s" in workspace "%s"...',
            [$nodeAggregateId, $target, $workspace]
        );
        $result = $this->retranslator->retranslateNode(
            ContentRepositoryId::fromString($contentRepository),
            WorkspaceName::fromString($workspace),
            NodeAggregateId::fromString($nodeAggregateId),
            DimensionSpacePoint::fromArray([$this->languageDimensionName => $target]),
        );

        // Distinct messages for skip / no-op / dispatched so misconfiguration is visible from CLI.
        if ($result->skippedReason !== null) {
            $this->outputLine(
                'Retranslation for node "%s" -> "%s" skipped: %s',
                [$nodeAggregateId, $target, $result->skippedReason]
            );
            return;
        }
        if ($result->isNoOp()) {
            $this->outputLine(
                'Retranslation for node "%s" -> "%s": nothing to do (no stale properties, no missing variants).',
                [$nodeAggregateId, $target]
            );
            return;
        }
        $this->outputLine(
            'Retranslation for node "%s" -> "%s": dispatched %d stale property update(s) and %d variant creation(s).',
            [$nodeAggregateId, $target, $result->stalePropertyCommandsDispatched, $result->variantCommandsDispatched],
        );
    }
}
