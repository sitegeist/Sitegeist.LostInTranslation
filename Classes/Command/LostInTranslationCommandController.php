<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Command;

use Neos\ContentGraph\DoctrineDbalAdapter\Domain\Repository\ContentSubgraph;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\AbsoluteNodePath;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindChildNodesFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context;

class LostInTranslationCommandController extends CommandController
{
    #[Flow\InjectConfiguration(path:'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    #[Flow\Inject]
    public ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    public Context $securityContext;

    public function translateCommand(string $source, string $target, string $contenRepository = 'default', string $workspace = 'live', string $nodePath = '/<Neos.Neos:Sites>'): void
    {
            $cr = $this->contentRepositoryRegistry->get(ContentRepositoryId::fromString($contenRepository));

            $workspaceName = WorkspaceName::fromString($workspace);

            if ($cr->findWorkspaceByName($workspaceName) === null) {
                $this->outputLine("workspace not fround");
                $this->quit(1);
            }

            $graph = $cr->getContentGraph($workspaceName);
            $originSubgraph = $graph->getSubgraph( DimensionSpacePoint::fromArray([$this->languageDimensionName => $source]), VisibilityConstraints::withoutRestrictions());
            $targetSubgraph = $graph->getSubgraph( DimensionSpacePoint::fromArray([$this->languageDimensionName => $target]), VisibilityConstraints::withoutRestrictions());

            $start = $originSubgraph->findNodeByAbsolutePath(AbsoluteNodePath::fromString($nodePath));

            if ($start === null) {
                $this->outputLine("No node found for path {$nodePath}");
                $this->quit(1);
            }
            $this->translateNodeRecursive($cr, $start, $originSubgraph, $targetSubgraph);
    }

    public function translateNodeRecursive(ContentRepository $cr, Node $originNode, ContentSubgraph $originSubgraph, ContentSubgraph $targetSubgraph): void
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
        foreach ($originSubgraph->findChildNodes($originNode->aggregateId,  FindChildNodesFilter::create())->getIterator() as $childNode) {
            $this->translateNodeRecursive($cr, $childNode, $originSubgraph, $targetSubgraph);
        }
    }
}
