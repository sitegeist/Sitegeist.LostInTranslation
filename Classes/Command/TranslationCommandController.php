<?php

namespace Sitegeist\LostInTranslation\Command;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Eel\Exception;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
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
     * @var PersistenceManagerInterface
     */
    protected $persistenceManager;

    /**
     * @param  string  $siteNodeName
     * @param  string|null  $nodeTypeFilter Expects exactly one document node type to loop through, otherwise all documents will be looped
     * @return void
     * @throws Exception
     * @throws StopCommandException
     */
    public function syncCommand(string $siteNodeName, string $nodeTypeFilter = null): void
    {
        /** @var Site|null $site */
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        if (is_null($site)) {
            $this->output->output('<error>The site with node name "%s" does not exist.</error>', [$siteNodeName]);
            $this->quit(1);
        }

        $siteNode = $this->getContentContext()->getNode('/sites/' . $siteNodeName);

        if (is_null($nodeTypeFilter)) {
            $nodeTypeFilter = '[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]';
        } else {
            $nodeTypeFilter = sprintf('[instanceof %s]', $nodeTypeFilter);
        }

        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', [$nodeTypeFilter]);
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $siteNode);

        $this->output->outputLine('Found %s document nodes', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
            $documentNodePath = $documentNode->getPath();
            $rootNode = $this->getContentContext()->getNode($documentNodePath);
            $this->processNode($rootNode);
            $this->persistenceManager->persistAll();
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->quit();
    }

    /**
     * @param  NodeInterface  $node
     * @return void
     */
    protected function processNode(NodeInterface $node): void
    {
        $this->nodeTranslationService->syncNode($node);

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
    protected function getContentContext(): Context
    {
        return $this->nodeTranslationService->getContextForLanguageDimensionAndWorkspaceName($this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset']);
    }
}
