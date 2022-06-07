<?php

namespace Sitegeist\LostInTranslation\Command;

use Doctrine\ORM\EntityManagerInterface;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Persistence\PersistenceManagerInterface;
use Neos\Neos\Domain\Model\Site;
use Neos\Neos\Domain\Repository\SiteRepository;
use Neos\Neos\Domain\Service\ContentContextFactory;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

class TranslationCommandController extends CommandController
{
    /**
     * @Flow\InjectConfiguration(package="Neos.Flow")
     * @var array
     */
    protected $flowSettings;

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
     * @var ContentContextFactory
     */
    protected $contentContextFactory;

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
     * @Flow\Internal
     *
     * @param  string  $siteNodeName
     * @param  bool  $translate
     * @return void
     */
    public function syncCommand(string $siteNodeName, bool $translate = false): void
    {
        /** @var Site|null $site */
        $site = $this->siteRepository->findOneByNodeName($siteNodeName);

        if (is_null($site)) {
            $this->output->output('<error>The site with node name "%s" does not exist.</error>', [$siteNodeName]);
            $this->quit(1);
        }

        $siteNode = $this->getContentContext()->getNode('/sites/' . $siteNodeName);

        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', ['[instanceof Neos.Neos:Document][!instanceof Neos.Neos:Shortcut]']);
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $siteNode);

        $this->output->outputLine('Found %s document nodes', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
            $documentNodePath = $documentNode->getPath();
            $rootNode = $this->getContentContext()->getNode($documentNodePath);
            $this->processNode($rootNode, $translate);
            $this->persistenceManager->persistAll();
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->quit();
    }

    /**
     * @param  NodeInterface  $node
     * @param  bool  $translate
     * @return void
     */
    protected function processNode(NodeInterface $node, bool $translate)
    {
        $this->syncNode($node, $translate);

        foreach ($node->getChildNodes() as $childNode) {
            if ($childNode->getNodeType()->isOfType('Neos.Neos:Document')) {
                continue;
            }

            $this->processNode($childNode, $translate);
        }
    }

    /**
     * @param  NodeInterface  $node
     * @param  bool  $translate
     * @return void
     */
    protected function syncNode(NodeInterface $node, bool $translate)
    {
        $isAutomaticTranslationEnabledForNodeType = $node->getNodeType()->getConfiguration('options.automaticTranslation') ?? true;
        if (!$isAutomaticTranslationEnabledForNodeType) {
            return;
        }

        $nodeSourceDimensionValue = $node->getContext()->getTargetDimensions()[$this->languageDimensionName];
        $defaultPreset = $this->contentDimensionConfiguration[$this->languageDimensionName]['defaultPreset'];

        if ($nodeSourceDimensionValue !== $defaultPreset) {
            return;
        }

        foreach($this->contentDimensionConfiguration[$this->languageDimensionName]['presets'] as $presetIdentifier => $languagePreset) {
            if ($nodeSourceDimensionValue === $presetIdentifier) {
                continue;
            }

            $translationStrategy = $languagePreset['options']['translationStrategy'] ?? null;
            if ($translationStrategy !== NodeTranslationService::TRANSLATION_STRATEGY_SYNC) {
                continue;
            }

            $context = $this->nodeTranslationService->getContextForLanguageDimensionAndWorkspaceName($presetIdentifier);
            if (!$node->isRemoved()) {
                $adoptedNode = $context->adoptNode($node);
                $this->nodeTranslationService->translateNode($node, $adoptedNode, $context, $translate);
            } else {
                $adoptedNode = $context->getNodeByIdentifier((string) $node->getNodeAggregateIdentifier());
                if ($adoptedNode !== null) $adoptedNode->setRemoved(true);
            }
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
