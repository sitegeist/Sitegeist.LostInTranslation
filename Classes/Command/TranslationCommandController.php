<?php

namespace Sitegeist\LostInTranslation\Command;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

class TranslationCommandController extends CommandController
{
    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array
     */
    protected $contentDimensionConfiguration;

    /**
     * @Flow\Inject
     * @var SiteRepository
     */
    protected $siteRepository;

    /**
     * @Flow\Inject
     * @var NodeTranslationService
     */
    protected $nodeTranslationService;

    /**
     * @Flow\Inject
     * @var EntityManagerInterface
     */
    protected $entityManager;

    /**
     * @Flow\Inject
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    /**
     * @param  string  $siteNodeName
     * @param  string|null  $from
     * @param  string|null  $to
     * @param  string  $nodeTypeFilter  Expects exactly one document node type to loop through, otherwise all documents will be looped
     * @return void
     * @throws Exception
     * @throws StopCommandException
     */
    public function syncCommand(string $siteNodeName, string $from = null, string $to = null, string $nodeTypeFilter = 'Neos.Neos:Document'): void
    {
        /** @var Site|null $site */
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        if (is_null($site)) {
            $this->output->output('<error>The site with node name "%s" does not exist.</error>', [$siteNodeName]);
            $this->quit(1);
        }

        $sourceContext = $this->getContentContext($from);
        $siteNode = $sourceContext->getNode('/sites/' . $siteNodeName);
        $nodeTypeFilter = sprintf('[instanceof %s]', $nodeTypeFilter);
        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', [$nodeTypeFilter]);
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $siteNode);

        $this->output->outputLine('Found %s document nodes', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

//        $targetContext = $this->getContentContext($to);
        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
//            $targetContext->adoptNode($documentNode, true);
            $documentNodePath = $documentNode->getPath();
            $rootNode = $this->getContentContext()->getNode($documentNodePath);
            $this->processNode($rootNode, $to);
            $this->nodeDataRepository->persistEntities();
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->quit();
    }

    /**
     * @param  NodeInterface  $node
     * @return void
     */
    protected function processNode(NodeInterface $node, string $targetPresetIdentifier = null): void
    {
        $this->nodeTranslationService->syncNode($node, 'live', $targetPresetIdentifier, true);

        foreach ($node->getChildNodes() as $childNode) {
            if ($childNode->getNodeType()->isOfType('Neos.Neos:Document') || $childNode->getNodeType()->isOfType('Neos.Neos:Shortcut')) {
                continue;
            }

            $this->processNode($childNode);
        }
    }

    /**
     * @return Context
     */
    protected function getContentContext(string $languageDimension = null): Context
    {
        return $this->nodeTranslationService->getContextForLanguageDimensionAndWorkspaceName($languageDimension ?: $this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset']);
    }
}
