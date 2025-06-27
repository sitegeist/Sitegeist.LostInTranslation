<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Infrastructure\DeepL;

use DeepL\DeepLClient;
use DeepL\GlossaryEntries;
use DeepL\GlossaryInfo;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeeplClientFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;

class DeepLGlossaryServiceTest extends UnitTestCase
{
    protected MockObject|GlossaryRepository $glossaryRepository;

    protected MockObject|DeeplClientFactory $deeplClientFactory;

    protected MockObject|DeepLClient $deeplClient;

    protected DeepLCacheService $deepLCacheService;

    protected DeeplGlossaryService $glossaryService;

    public function setUp(): void
    {
        $this->glossaryRepository = $this->createMock(GlossaryRepository::class);
        $this->deeplClientFactory = $this->createMock(DeeplClientFactory::class);
        $this->deeplClient = $this->createMock(DeepLClient::class);

        $this->deeplClientFactory->expects($this->any())->method('createDeepLClient')->willReturn($this->deeplClient);

        $this->glossaryService = new DeeplGlossaryService(
            $this->deeplClientFactory,
            $this->glossaryRepository
        );

        $this->inject($this->glossaryService, 'glossaryLabelPrefix', '__prefix__');
    }

    /** @test */
    public function findGlossaryIdPassedToLocalRepository(): void
    {
        $dummyGlossary = Glossary::create('de', 'en');
        $dummyGlossary->synchronizationIdentifier = '__dummy_identifier__';
        $this->glossaryRepository->expects($this->once())->method('findOneBySourceAndTargetLanguageKey')->with('de', 'en')->willReturn($dummyGlossary);
        $this->assertEquals('__dummy_identifier__', $this->glossaryService->findGlossaryId('de', 'en'));
    }

    /** @test */
    public function uploadRemoteGlossaryWillUploadWithPrefix(): void
    {
        $glossaryMock = $this->createMock(Glossary::class);
        $glossaryMock->sourceLanguageKey = 'de';
        $glossaryMock->targetLanguageKey = 'en';
        $glossaryMock->expects($this->any())->method('getLabel')->willReturn('de -> en');
        $glossaryMock->expects($this->any())->method('getEntriesAsAssociativeArray')->willReturn(['nudel' => 'noodle']);

        $glossaryInfo = new GlossaryInfo('__dummy_identifier__', '__prefix__::de -> en', true, 'de', 'en', new \DateTime('now') , 0);

        $this->deeplClient->expects($this->once())->method('createGlossary')->with(
            '__prefix__::de -> en',
            'de',
            'en',
            GlossaryEntries::fromEntries(['nudel' => 'noodle'])
        )->willReturn($glossaryInfo);

        $this->assertEquals( '__dummy_identifier__' , $this->glossaryService->uploadRemoteGlossary($glossaryMock));
    }

    /** @test */
    public function deleteRemoteGlossaryWillDeleteWhenPrefixMatches(): void
    {
        $glossaryInfo = new GlossaryInfo('__dummy_identifier__', '__prefix__::de -> en', true, 'de', 'en', new \DateTime('now') , 0);

        $this->deeplClient->expects($this->once())->method('getGlossary')->with(
            '__identifier__',
        )->willReturn($glossaryInfo);

        $this->deeplClient->expects($this->once())->method('deleteGlossary')->with(
            '__identifier__',
        );

        $this->glossaryService->deleteRemoteGlossary('__identifier__');
    }

    /** @test */
    public function deleteRemoteGlossaryWillNotDeleteWhenPrefixMatches(): void
    {
        $glossaryInfo = new GlossaryInfo('__dummy_identifier__', '__not_the_prefix__::de -> en', true, 'de', 'en', new \DateTime('now') , 0);

        $this->deeplClient->expects($this->once())->method('getGlossary')->with(
            '__identifier__',
        )->willReturn($glossaryInfo);

        $this->deeplClient->expects($this->never())->method('deleteGlossary');

        $this->glossaryService->deleteRemoteGlossary('__identifier__');
    }

    /** @test */
    public function listRemoteGlossaryWillOnlyReturnGlossariewWithMatchingPrefix(): void
    {
        $glossaryInfoA = new GlossaryInfo('__id_a__', '__not_the_prefix__::es -> pt', true, 'es', 'pt', new \DateTime('now') , 0);
        $glossaryInfoB = new GlossaryInfo('__id_b__', '__prefix__::de -> en', true, 'de', 'en', new \DateTime('now') , 0);
        $glossaryInfoC = new GlossaryInfo('__id_c__', '__prefix__::en -> de', true, 'en', 'de', new \DateTime('now') , 0);
        $glossaryInfoD = new GlossaryInfo('__id_d__', '__not_the_prefix__::pt -> es', true, 'pt', 'es', new \DateTime('now') , 0);

        $this->deeplClient->expects($this->once())->method('listGlossaries')->willReturn([
            $glossaryInfoA, $glossaryInfoB, $glossaryInfoC, $glossaryInfoD
        ]);

        $this->assertEquals( [$glossaryInfoB, $glossaryInfoC], $this->glossaryService->listRemoteGlossaries());
    }
}
