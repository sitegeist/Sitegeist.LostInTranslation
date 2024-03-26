<?php

namespace Sitegeist\LostInTranslation\Tests\Functional\ContentRepository;

use Exception;
use Faker\Factory;
use Faker\Generator;
use Neos\ContentRepository\Domain\Factory\NodeFactory;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
use Neos\ContentRepository\Exception\NodeTypeNotFoundException;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Flow\Utility\Algorithms;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;
use Sitegeist\LostInTranslation\Tests\Functional\AbstractFunctionalTestCase;

class NodeTranslationServiceTest extends AbstractFunctionalTestCase
{
    /**
     * @var Context
     */
    protected $germanUserContext;

    /**
     * @var Context
     */
    protected $germanLiveContext;

    /**
     * @var Context
     */
    protected $englishLiveContext;

    /**
     * @var Context
     */
    protected $italianLiveContext;

    /**
     * @var Context
     */
    protected $italianUserContext;

    /**
     * @var ContextFactoryInterface
     */
    protected $contextFactory;

    /**
     * @var Workspace
     */
    protected $liveWorkspace;

    /**
     * @var Workspace
     */
    protected $userWorkspace;

    /**
     * @var DeepLTranslationService|MockObject
     */
    protected $deeplServiceMock;

    /**
     * @var Generator
     */
    protected $faker;

    /**
     * @var NodeDataRepository
     */
    protected $nodeDataRepository;

    protected string $liveWorkspaceName;
    protected string $userWorkspaceName;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->liveWorkspaceName = uniqid('live-', true);
        $this->userWorkspaceName = uniqid('user-', true);
        $this->setUpWorkspacesAndContexts();

        // Mock
        $this->deeplServiceMock = $this->getMockBuilder(DeepLTranslationService::class)->getMock();
        $nodeTranslationService = $this->objectManager->get(NodeTranslationService::class);
        $this->inject($nodeTranslationService, 'translationService', $this->deeplServiceMock);
        $this->inject($nodeTranslationService, 'liveWorkspaceName', $this->liveWorkspaceName);

        // Fakers
        $this->faker = Factory::create();
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        $this->saveNodesAndTearDown();
        parent::tearDown();
    }

    /**
     * @test
     * @return void
     * @throws NodeException
     */
    public function newNodeInGermanIsAutomaticallyAndCorrectlySyncedToEnglish(): void
    {
        $germanString = $this->faker->text(100);
        $englishString = $this->faker->text(100);
        $this->deeplServiceMock->method('translate')->with(['inlineEditableStringProperty' => $germanString], 'en')->willReturn(['inlineEditableStringProperty' => $englishString]);

        $nodeInGerman = $this->createTestNode(['inlineEditableStringProperty' => $germanString, 'stringProperty' => $germanString]);
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInEnglish = $this->englishLiveContext->getNode('/new-node');

        $this->assertTrue(!is_null($nodeInEnglish), 'The node in German was automatically synced into English');
        $this->assertEquals($englishString, $nodeInEnglish->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into English');
        $this->assertEquals($germanString, $nodeInEnglish->getProperty('stringProperty'), 'The non-inline editable property was not translated into English');
        $this->assertInternalProperties($nodeInGerman, $nodeInEnglish);
    }

    /**
     * @test
     * @return void
     * @throws NodeException|IllegalObjectTypeException
     */
    public function updatedNodeInGermanIsCorrectlySyncedToEnglish(): void
    {
        $germanString = $this->faker->text(100);
        $englishString = $this->faker->text(100);
        $newGermanString = $this->faker->text(100);
        $newEnglishString = $this->faker->text(100);
        $this->deeplServiceMock->method('translate')
            ->withConsecutive(
                [['inlineEditableStringProperty' => $germanString], 'en'],
                [['inlineEditableStringProperty' => $newGermanString], 'en'],
            )
            ->willReturnOnConsecutiveCalls(
                ['inlineEditableStringProperty' => $englishString],
                ['inlineEditableStringProperty' => $newEnglishString],
            );

        $nodeInGerman = $this->createTestNode(['inlineEditableStringProperty' => $germanString, 'stringProperty' => $germanString]);
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInEnglish = $this->englishLiveContext->getNode('/new-node');

        $this->assertTrue(!is_null($nodeInEnglish), 'The node in German was automatically synced into English');
        $this->assertEquals($englishString, $nodeInEnglish->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into English');
        $this->assertEquals($germanString, $nodeInEnglish->getProperty('stringProperty'), 'The non-inline editable property was not translated into English');
        $this->assertInternalProperties($nodeInGerman, $nodeInEnglish);

        // Step 2
        $nodeInGerman2 = $this->germanUserContext->getNode('/new-node');
        $nodeInGerman2->setWorkspace($this->userWorkspace);
        $nodeInGerman2->setProperty('inlineEditableStringProperty', $newGermanString);
        $nodeInGerman2->setProperty('stringProperty', $newGermanString);
        $nodeInGerman2->setHidden(true);
        $this->userWorkspace->publishNode($nodeInGerman2, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInEnglish2 = $this->englishLiveContext->getNode('/new-node');

        $this->assertEquals($newEnglishString, $nodeInEnglish2->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into English');
        $this->assertEquals($newGermanString, $nodeInEnglish2->getProperty('stringProperty'), 'The non-inline editable property was not translated into English');
        $this->assertInternalProperties($nodeInGerman2, $nodeInEnglish2);
    }

    /**
     * @test
     * @return void
     * @throws NodeException
     */
    public function newNodeInGermanIsNotAutomaticallySyncedToItalian(): void
    {
        $this->deeplServiceMock->method('translate')
            ->withConsecutive([['inlineEditableStringProperty' => 'Hallo Welt!'], 'en'], [['inlineEditableStringProperty' => 'Hallo Welt!'], 'it'])
            ->willReturnOnConsecutiveCalls(['inlineEditableStringProperty' => 'Hello World!'], ['inlineEditableStringProperty' => 'Hello World!']);

        $nodeInGerman = $this->createTestNode();
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInItalian = $this->italianLiveContext->getNode('/new-node');
        $this->assertTrue(is_null($nodeInItalian), 'The node in German was not automatically synced into Italian');
    }

    /**
     * @test
     * @return void
     * @throws NodeException
     */
    public function newNodeInGermanIsOnAdoptionSyncedToItalian(): void
    {
        $germanString = $this->faker->text(100);
        $englishString = $this->faker->text(100);
        $italianString = $this->faker->text(100);
        $this->deeplServiceMock->method('translate')
            ->withConsecutive([['inlineEditableStringProperty' => $germanString], 'en'], [['inlineEditableStringProperty' => $germanString], 'it'])
            ->willReturnOnConsecutiveCalls(['inlineEditableStringProperty' => $englishString], ['inlineEditableStringProperty' => $italianString]);

        $nodeInGerman = $this->createTestNode(['inlineEditableStringProperty' => $germanString, 'stringProperty' => $germanString]);
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $this->italianUserContext->adoptNode($nodeInGerman);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInItalian = $this->italianUserContext->getNode('/new-node');

        $this->assertTrue(!is_null($nodeInItalian), 'The node in German was automatically synced into Italian');
        $this->assertEquals($italianString, $nodeInItalian->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into Italian');
        $this->assertEquals($germanString, $nodeInItalian->getProperty('stringProperty'), 'The non-inline editable property was not translated into Italian');
        $this->assertInternalProperties($nodeInGerman, $nodeInItalian);
    }

    /**
     * @test
     * @return void
     * @throws Exception
     */
    public function movedNodeBeforeInGermanIsAlsoMovedBeforeInEnglish(): void
    {
        // Step 1: create two nodes on the same level
        $firstNodeInGerman = $this->createTestNode([], 'new-node-1');
        $secondNodeInGerman = $this->createTestNode([], 'new-node-2');
        $this->userWorkspace->publishNodes([$firstNodeInGerman, $secondNodeInGerman], $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $firstNodeInEnglish = $this->englishLiveContext->getNode('/new-node-1');
        $secondNodeInEnglish = $this->englishLiveContext->getNode('/new-node-2');

        $this->assertTrue(!is_null($firstNodeInEnglish), 'The parent node in German was automatically synced into English');
        $this->assertTrue(!is_null($secondNodeInEnglish), 'The child node in German was automatically synced into English');

        // Step 2: move the second node before the first node
        $firstNodeInGerman2 = $this->germanUserContext->getNode('/new-node-1');
        $secondNodeInGerman2 = $this->germanUserContext->getNode('/new-node-2');
        $secondNodeInGerman2->setWorkspace($this->userWorkspace);
        $secondNodeInGerman2->moveBefore($firstNodeInGerman2);
        $this->userWorkspace->publishNode($secondNodeInGerman2, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $firstNodeInEnglish2 = $this->englishLiveContext->getNode('/new-node-1');
        $secondNodeInEnglish2 = $this->englishLiveContext->getNode('/new-node-2');

        $this->assertTrue(!is_null($secondNodeInEnglish2), 'The second node in German was correctly moved after the first node in English');
        $this->assertGreaterThan($secondNodeInGerman2->getIndex(), $firstNodeInGerman2->getIndex());
        $this->assertGreaterThan($secondNodeInEnglish2->getIndex(), $firstNodeInEnglish2->getIndex());
        $this->assertInternalProperties($secondNodeInGerman2, $secondNodeInEnglish2);
    }

    /**
     * @test
     * @return void
     * @throws Exception
     */
    public function movedNodeIntoInGermanIsAlsoMovedIntoInEnglish(): void
    {
        // Step 1: create two nodes on the same level
        $parentNodeInGerman = $this->createTestNode([], 'new-node-1');
        $childNodeInGerman = $this->createTestNode([], 'new-node-2');
        $this->userWorkspace->publishNodes([$parentNodeInGerman, $childNodeInGerman], $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $parentNodeInEnglish = $this->englishLiveContext->getNode('/new-node-1');
        $childNodeInEnglish = $this->englishLiveContext->getNode('/new-node-2');

        $this->assertTrue(!is_null($parentNodeInEnglish), 'The parent node in German was automatically synced into English');
        $this->assertTrue(!is_null($childNodeInEnglish), 'The child node in German was automatically synced into English');

        // Step 2: move the child node into the parent node
        $childNodeInGerman2 = $this->germanUserContext->getNode('/new-node-2');
        $childNodeInGerman2->setWorkspace($this->userWorkspace);
        $childNodeInGerman2->moveInto($parentNodeInGerman);
        $this->userWorkspace->publishNode($childNodeInGerman2, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $childNodeInEnglish2 = $this->englishLiveContext->getNode('/new-node-1/new-node-2');

        $this->assertTrue(!is_null($childNodeInEnglish2), 'The child node in German was correctly moved into the parent node in English');
        $this->assertInternalProperties($childNodeInGerman2, $childNodeInEnglish2);
    }

    /**
     * @test
     * @return void
     * @throws Exception
     */
    public function movedNodeAfterInGermanIsAlsoMovedAfterInEnglish(): void
    {
        // Step 1: create two nodes on the same level
        $firstNodeInGerman = $this->createTestNode([], 'new-node-1');
        $secondNodeInGerman = $this->createTestNode([], 'new-node-2');
        $this->userWorkspace->publishNodes([$firstNodeInGerman, $secondNodeInGerman], $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $firstNodeInEnglish = $this->englishLiveContext->getNode('/new-node-1');
        $secondNodeInEnglish = $this->englishLiveContext->getNode('/new-node-2');

        $this->assertTrue(!is_null($firstNodeInEnglish), 'The parent node in German was automatically synced into English');
        $this->assertTrue(!is_null($secondNodeInEnglish), 'The child node in German was automatically synced into English');

        // Step 2: move the second node after the first node
        $firstNodeInGerman2 = $this->germanUserContext->getNode('/new-node-1');
        $secondNodeInGerman2 = $this->germanUserContext->getNode('/new-node-2');
        $secondNodeInGerman2->setWorkspace($this->userWorkspace);
        $secondNodeInGerman2->moveAfter($firstNodeInGerman2);
        $this->userWorkspace->publishNode($secondNodeInGerman2, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $firstNodeInEnglish2 = $this->englishLiveContext->getNode('/new-node-1');
        $secondNodeInEnglish2 = $this->englishLiveContext->getNode('/new-node-2');

        $this->assertTrue(!is_null($secondNodeInEnglish2), 'The second node in German was correctly moved after the first node in English');
        $this->assertLessThan($secondNodeInGerman2->getIndex(), $firstNodeInGerman2->getIndex());
        $this->assertLessThan($secondNodeInEnglish2->getIndex(), $firstNodeInEnglish2->getIndex());
        $this->assertInternalProperties($secondNodeInGerman2, $secondNodeInEnglish2);
    }

    /**
     * @test
     *
     * @return void
     * @throws Exception
     */
    public function copyBeforeNodeInGermanIsAlsoCopiedBeforeInEnglish(): void
    {
        $nodeInGerman = $this->createTestNode();
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInGerman2 = $this->germanUserContext->getNode('/new-node');
        $nodeInGerman2->setWorkspace($this->userWorkspace);
        $copiedNodeInGerman = $nodeInGerman2->copyBefore($nodeInGerman2, 'copied-node');
        $this->userWorkspace->publishNode($copiedNodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInGerman3 = $this->germanLiveContext->getNode('/new-node');
        $copiedNodeInGerman2 = $this->germanLiveContext->getNode('/copied-node');
        $nodeInEnglish = $this->englishLiveContext->getNode('/new-node');
        $copiedNodeInEnglish = $this->englishLiveContext->getNode('/copied-node');

        $this->assertTrue(!is_null($nodeInGerman3));
        $this->assertTrue(!is_null($copiedNodeInGerman2));
        $this->assertTrue(!is_null($nodeInEnglish));
        $this->assertTrue(!is_null($copiedNodeInEnglish));
        $this->assertGreaterThan($copiedNodeInEnglish->getIndex(), $nodeInEnglish->getIndex());
    }

    /**
     * @test
     *
     * @return void
     * @throws Exception
     */
    public function copyIntoNodeInGermanIsAlsoCopiedIntoInEnglish(): void
    {
        $nodeInGerman = $this->createTestNode();
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInGerman2 = $this->germanUserContext->getNode('/new-node');
        $nodeInGerman2->setWorkspace($this->userWorkspace);
        $copiedNodeInGerman = $nodeInGerman2->copyInto($nodeInGerman2, 'copied-node');
        $this->userWorkspace->publishNode($copiedNodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $this->assertTrue(!is_null($this->germanLiveContext->getNode('/new-node')));
        $this->assertTrue(!is_null($this->germanLiveContext->getNode('/new-node/copied-node')));
        $this->assertTrue(!is_null($this->englishLiveContext->getNode('/new-node')));
        $this->assertTrue(!is_null($this->englishLiveContext->getNode('/new-node/copied-node')));
    }

    /**
     * @test
     * @return void
     * @throws Exception
     */
    public function copyAfterNodeInGermanIsAlsoCopiedAfterInEnglish(): void
    {
        $nodeInGerman = $this->createTestNode();
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInGerman2 = $this->germanUserContext->getNode('/new-node');
        $nodeInGerman2->copyAfter($nodeInGerman2, 'copied-node');

        $copiedNodeInGerman1 = $this->germanUserContext->getNode('/copied-node');
        $copiedNodeInGerman1->setWorkspace($this->userWorkspace);
        $this->userWorkspace->publish($this->liveWorkspace);

        $this->saveNodesAndTearDown();
        $this->setUpWorkspacesAndContexts();

        $nodeInGerman3 = $this->germanLiveContext->getNode('/new-node');
        $copiedNodeInGerman2 = $this->germanLiveContext->getNode('/copied-node');
        $nodeInEnglish = $this->englishLiveContext->getNode('/new-node');
        $copiedNodeInEnglish = $this->englishLiveContext->getNode('/copied-node');

        $this->assertTrue(!is_null($nodeInGerman3));
        $this->assertTrue(!is_null($copiedNodeInGerman2));
        $this->assertTrue(!is_null($nodeInEnglish));
        $this->assertTrue(!is_null($copiedNodeInEnglish));
        $this->assertLessThan($copiedNodeInEnglish->getIndex(), $nodeInEnglish->getIndex());
    }

    /**
     * @test
     * @return void
     */
    public function removedNodeInGermanIsAlsoRemovedInEnglish(): void
    {
        // Step 1: create a node in German
        $nodeInGerman = $this->createTestNode();
        $nodeInGerman->getWorkspace()->publishNode($nodeInGerman, $this->liveWorkspace);
        $nodeInEnglish = $this->getContext($this->liveWorkspaceName, 'en')->getNode('/new-node');

        $this->assertTrue(!is_null($nodeInEnglish), 'The parent node in German was automatically synced into English');

        // Step 2: the node in German is deleted and is also expected to be deleted in English
        $nodeInGerman = $this->getContext($this->userWorkspaceName, 'de')->getNode('/new-node');
        $nodeInGerman->remove();
        $nodeInGerman->getWorkspace()->publishNode($nodeInGerman, $this->liveWorkspace);

        $nodeInGerman = $this->getContext($this->liveWorkspaceName, 'de')->getNode('/new-node');
        $nodeInEnglish = $this->getContext($this->liveWorkspaceName, 'en')->getNode('/new-node');

        $this->assertTrue(is_null($nodeInGerman), 'The node in German was removed');
        $this->assertTrue(is_null($nodeInEnglish), 'The node in English was removed');
    }

    /**
     * @param string $nodeType
     *
     * @return NodeType|null
     */
    protected function getNodeType(string $nodeType): ?NodeType
    {
        try {
            $nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
            return $nodeTypeManager->getNodeType($nodeType);
        } catch (NodeTypeNotFoundException) {
            return null;
        }
    }

    /**
     * @param array  $properties
     * @param string $name
     *
     * @return NodeInterface
     * @throws Exception
     */
    protected function createTestNode(array $properties = [], string $name = 'new-node'): NodeInterface
    {
        $rootNodeInSourceContext = $this->germanUserContext->getRootNode();
        $rootNodeInSourceContext->setWorkspace($this->userWorkspace);
        $node = $rootNodeInSourceContext->createNode($name, $this->getNodeType('Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation'), Algorithms::generateUUID());
        foreach ($properties as $propertyName => $propertyValue) {
            $node->setProperty($propertyName, $propertyValue);
        }
        return $node;
    }

    /**
     * @param NodeInterface $sourceNode
     * @param NodeInterface $targetNode
     *
     * @return void
     */
    protected function assertInternalProperties(NodeInterface $sourceNode, NodeInterface $targetNode): void
    {
        $this->assertEquals($sourceNode->getHiddenBeforeDateTime(), $targetNode->getHiddenBeforeDateTime(), 'hiddenBeforeDateTime was correctly synced');
        $this->assertEquals($sourceNode->getHiddenAfterDateTime(), $targetNode->getHiddenAfterDateTime(), 'hiddenAfterDateTime was correctly synced');
        $this->assertEquals($sourceNode->getIndex(), $targetNode->getIndex(), 'index was correctly synced');
        $this->assertEquals($sourceNode->isHidden(), $targetNode->isHidden(), 'isHidden was correctly synced');
        $this->assertEquals($sourceNode->isHiddenInIndex(), $targetNode->isHiddenInIndex(), 'isHiddenInIndex was correctly synced');
        $this->assertEquals($sourceNode->getNodeType(), $targetNode->getNodeType(), 'nodeType was correctly synced');
    }

    /**
     * @param string $workspaceName
     * @param string $targetLanguageDimension
     *
     * @return Context
     */
    protected function getContext(string $workspaceName, string $targetLanguageDimension): Context
    {
        return $this->contextFactory->create($this->getContextConfiguration($workspaceName, $targetLanguageDimension));
    }

    /**
     * @param string $workspaceName
     * @param string $targetLanguageDimension
     *
     * @return array
     */
    protected function getContextConfiguration(string $workspaceName, string $targetLanguageDimension): array
    {
        return [
            'workspaceName' => $workspaceName,
            'dimensions' => ['language' => [$targetLanguageDimension]],
            'invisibleContentShown' => true,
            'removedContentShown' => true,
            'inaccessibleContentShown' => true
        ];
    }

    /**
     * @return void
     * @throws IllegalObjectTypeException
     */
    protected function setUpWorkspacesAndContexts(): void
    {
        $this->persistenceManager->clearState();
        $this->nodeDataRepository = new NodeDataRepository();
        $workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);

        $this->liveWorkspace = $workspaceRepository->findByIdentifier($this->liveWorkspaceName);
        if ($this->liveWorkspace === null) {
            $this->liveWorkspace = new Workspace($this->liveWorkspaceName);
            $workspaceRepository->add($this->liveWorkspace);
            $this->persistenceManager->persistAll();
        }

        $this->userWorkspace = $workspaceRepository->findByIdentifier($this->userWorkspaceName);
        if ($this->userWorkspace === null) {
            $this->userWorkspace = new Workspace($this->userWorkspaceName, $this->liveWorkspace);
            $workspaceRepository->add($this->userWorkspace);
            $this->persistenceManager->persistAll();
        }

        $this->contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
        // The root live context, where our original content is live
        $this->germanLiveContext = $this->contextFactory->create([
            'workspaceName' => $this->liveWorkspaceName,
            'dimensions' => ['language' => ['de']],
            'invisibleContentShown' => true,
            'removedContentShown' => true
        ]);
        // The root user context, where the test user creates content, which is eventually published to live
        $this->germanUserContext = $this->contextFactory->create([
            'workspaceName' => $this->userWorkspaceName,
            'dimensions' => ['language' => ['de']],
            'targetDimensions' => ['language' => 'de'],
            'invisibleContentShown' => true,
            'removedContentShown' => true
        ]);
        // The English live context, where content from the German live context is synced automatically
        $this->englishLiveContext = $this->contextFactory->create([
            'workspaceName' => $this->liveWorkspaceName,
            'dimensions' => ['language' => ['en']],
            'targetDimensions' => ['language' => 'en'],
            'invisibleContentShown' => true,
            'removedContentShown' => true
        ]);
        // The Italian live context, where content from the German live context is only copied once on node adoption
        $this->italianLiveContext = $this->contextFactory->create([
            'workspaceName' => $this->liveWorkspaceName,
            'dimensions' => ['language' => ['it']],
            'targetDimensions' => ['language' => 'it'],
            'invisibleContentShown' => true,
            'removedContentShown' => true
        ]);
        $this->italianUserContext = $this->contextFactory->create([
            'workspaceName' => $this->userWorkspaceName,
            'dimensions' => ['language' => ['it']],
            'targetDimensions' => ['language' => 'it'],
            'invisibleContentShown' => true,
            'removedContentShown' => true
        ]);

        $this->persistenceManager->persistAll();
    }

    /**
     * @return void
     */
    protected function saveNodesAndTearDown(): void
    {
        if ($this->nodeDataRepository !== null) {
            $this->nodeDataRepository->flushNodeRegistry();
        }
        /** @var NodeFactory $nodeFactory */
        $nodeFactory = $this->objectManager->get(NodeFactory::class);
        $nodeFactory->reset();
        $this->contextFactory->reset();
        $nodeTranslationService = $this->objectManager->get(NodeTranslationService::class);
        $nodeTranslationService->resetContextCache();

        $this->persistenceManager->persistAll();
        $this->persistenceManager->clearState();
        $this->nodeDataRepository = null;
        $this->germanLiveContext->getFirstLevelNodeCache()->flush();
        $this->germanLiveContext = null;
        $this->germanUserContext->getFirstLevelNodeCache()->flush();
        $this->germanUserContext = null;
        $this->englishLiveContext->getFirstLevelNodeCache()->flush();
        $this->englishLiveContext = null;
        $this->italianUserContext->getFirstLevelNodeCache()->flush();
        $this->italianLiveContext = null;
        $this->italianUserContext->getFirstLevelNodeCache()->flush();
        $this->italianUserContext = null;
    }
}
