<?php

namespace Sitegeist\LostInTranslation\Tests\Functional\ContentRepository;

use Faker\Factory;
use Faker\Generator;
use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Model\NodeTemplate;
use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\ContentRepository\Domain\Service\ContextFactoryInterface;
use Neos\ContentRepository\Domain\Service\NodeTypeManager;
use Neos\ContentRepository\Exception\NodeException;
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
    protected $germanFaker;

    /**
     * @var Generator
     */
    protected $englishFaker;

    /**
     * @var Generator
     */
    protected $italianFaker;

    /**
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $workspaceRepository = $this->objectManager->get(WorkspaceRepository::class);
        $this->liveWorkspace = new Workspace('live');
        $workspaceRepository->add($this->liveWorkspace);
        $this->userWorkspace = new Workspace('test', $this->liveWorkspace);
        $workspaceRepository->add($this->userWorkspace);
        $this->persistenceManager->persistAll();
        $this->contextFactory = $this->objectManager->get(ContextFactoryInterface::class);
        // The root live context, where our original content is live
        $this->germanLiveContext = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => ['language' => ['de']]]);
        // The root user context, where the test user creates content, which is eventually published to live
        $this->germanUserContext = $this->contextFactory->create(['workspaceName' => 'test', 'dimensions' => ['language' => ['de']]]);
        // The English live context, where content from the German live context is synced automatically
        $this->englishLiveContext = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => ['language' => ['en']]]);
        // The Italian live context, where content from the German live context is only copied once on node adoption
        $this->italianLiveContext = $this->contextFactory->create(['workspaceName' => 'live', 'dimensions' => ['language' => ['it']]]);
        $this->italianUserContext = $this->contextFactory->create(['workspaceName' => 'test', 'dimensions' => ['language' => ['it']]]);

        // Mock
        $this->deeplServiceMock = $this->getMockBuilder(DeepLTranslationService::class)->getMock();
        $nodeTranslationService = $this->objectManager->get(NodeTranslationService::class);
        $this->inject($nodeTranslationService, 'translationService', $this->deeplServiceMock);

        // Fakers
        $this->germanFaker = Factory::create('de_DE');
        $this->englishFaker = Factory::create('en_GB');
        $this->italianFaker = Factory::create('it_IT');
    }

    /**
     * @return void
     */
    public function tearDown(): void
    {
        parent::tearDown();
        $this->inject($this->contextFactory, 'contextInstances', []);
    }

    /**
     * @test
     * @return void
     * @throws NodeException
     */
    public function newNodeInGermanIsAutomaticallyAndCorrectlySyncedToEnglish(): void
    {
        $germanString = $this->germanFaker->text(100);
        $englishString = $this->englishFaker->text(100);
        $this->deeplServiceMock->method('translate')->with(['inlineEditableStringProperty' => $germanString], 'en')->willReturn(['inlineEditableStringProperty' => $englishString]);

        $nodeInGerman = $this->createTestNode(['inlineEditableStringProperty' => $germanString, 'stringProperty' => $germanString]);
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);
        $nodeInEnglish = $this->englishLiveContext->getNode('/new-node');

        $this->assertTrue(!is_null($nodeInEnglish), 'The node in German was automatically synced into English');
        $this->assertEquals($englishString, $nodeInEnglish->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into English');
        $this->assertEquals($germanString, $nodeInEnglish->getProperty('stringProperty'), 'The non-inline editable property was not translated into English');
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
        $this->deeplServiceMock->method('translate')
            ->withConsecutive([['inlineEditableStringProperty' => 'Hallo Welt!'], 'en'], [['inlineEditableStringProperty' => 'Hallo Welt!'], 'it'])
            ->willReturnOnConsecutiveCalls(['inlineEditableStringProperty' => 'Hello World!'], ['inlineEditableStringProperty' => 'Ciao mondo!']);

        $nodeInGerman = $this->createTestNode(['inlineEditableStringProperty' => 'Hallo Welt!', 'stringProperty' => 'Hallo Welt!']);
        $this->userWorkspace->publishNode($nodeInGerman, $this->liveWorkspace);
        $nodeInItalian = $this->italianUserContext->adoptNode($nodeInGerman);

        $this->assertTrue(!is_null($nodeInItalian), 'The node in German was automatically synced into Italian');
        $this->assertEquals('Ciao mondo!', $nodeInItalian->getProperty('inlineEditableStringProperty'), 'The inline editable property was translated into Italian');
        $this->assertEquals('Hallo Welt!', $nodeInItalian->getProperty('stringProperty'), 'The non-inline editable property was not translated into Italian');
    }

    protected function getNodeType(string $nodeType): ?NodeType
    {
        $nodeTypeManager = $this->objectManager->get(NodeTypeManager::class);
        return $nodeTypeManager->getNodeType($nodeType);
    }

    /**
     * @param array  $properties
     * @param string $name
     *
     * @return NodeInterface
     * @throws \Exception
     */
    protected function createTestNode(array $properties = [], string $name = 'new-node'): NodeInterface
    {
        $identifier = Algorithms::generateUUID();
        $template = new NodeTemplate();
        $template->setName($name);
        $template->setIdentifier($identifier);
        $template->setNodeType($this->getNodeType('Sitegeist.LostInTranslation.Testing:NodeWithAutomaticTranslation'));
        foreach ($properties as $propertyName => $propertyValue) {
            $template->setProperty($propertyName, $propertyValue);
        }

        $rootNodeInSourceContext = $this->germanUserContext->getRootNode();

        return $rootNodeInSourceContext->createNodeFromTemplate($template);
    }
}
