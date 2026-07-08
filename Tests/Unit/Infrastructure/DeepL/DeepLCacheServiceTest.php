<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKeyFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheIdentifierFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;

class DeepLCacheServiceTest extends UnitTestCase
{
    protected MockObject|VariableFrontend $translationCache;

    protected MockObject|DeepLCacheIdentifierFactory $cacheIdentifierFactory;

    protected DeepLCacheService $deepLCacheService;

    public function setUp(): void
    {
        $this->translationCache = $this->createMock(VariableFrontend::class);
        $this->cacheIdentifierFactory = $this->createMock(DeepLCacheIdentifierFactory::class);

        $this->deepLCacheService = new DeepLCacheService();
        $this->inject($this->deepLCacheService, 'cacheIdentifierFactory', $this->cacheIdentifierFactory);
        $this->inject($this->deepLCacheService, 'translationCache', $this->translationCache);
        $this->inject($this->deepLCacheService, 'enabled', true);
    }

    /** @test */
    public function getReturnsValueFromCacheAfterObtainingIdentifierWhenCacheContainedResult(): void
    {
        $this->cacheIdentifierFactory->expects($this->once())->method('createEntryIdentifier')->with("source", "SL", "TL")->willReturn('__cache_key__');
        $this->translationCache->expects($this->once())->method('get')->with("__cache_key__")->willReturn('result');
        $result = $this->deepLCacheService->get("source", "SL", "TL");
        $this->assertEquals('result', $result);
    }

    /** @test */
    public function getReturnsNullFromCacheAfterObtainingIdentifierWhenCacheIsEmpty(): void
    {
        $this->cacheIdentifierFactory->expects($this->once())->method('createEntryIdentifier')->with("source", "SL", "TL")->willReturn('__cache_key__');
        $this->translationCache->expects($this->once())->method('get')->with("__cache_key__")->willReturn(false);
        $result = $this->deepLCacheService->get("source", "SL", "TL");
        $this->assertNull($result);
    }

    /** @test */
    public function valueIsStoredInCacheAfterCalculatingEntryIdentifier(): void
    {
        $this->cacheIdentifierFactory->expects($this->once())->method('createEntryIdentifier')->with("source", "SL", "TL")->willReturn('__cache_key__');
        $this->translationCache->expects($this->once())->method('set')->with("__cache_key__")->willReturn(false);
        $this->deepLCacheService->set("source", "target", "SL", "TL");
    }

    /** @test */
    public function serviceKnowsWhenCacheIsEnabled(): void
    {
        $this->assertTrue($this->deepLCacheService->isEnabled());
    }

    /** @test */
    public function serviceKnowsWhenCacheIsDisabled(): void
    {
        $this->inject($this->deepLCacheService, 'enabled', false);
        $this->assertFalse($this->deepLCacheService->isEnabled());
    }

    /** @test */
    public function whenDisabledGetReturnsNull(): void
    {
        $this->inject($this->deepLCacheService, 'enabled', false);

        $this->cacheIdentifierFactory->expects($this->never())->method('createEntryIdentifier');
        $this->translationCache->expects($this->never())->method('get');
        $result = $this->deepLCacheService->get("source", "SL", "TL");
        $this->assertNull($result);
    }

    /** @test */
    public function whenDisabledSetHasNoEffect(): void
    {
        $this->inject($this->deepLCacheService, 'enabled', false);

        $this->cacheIdentifierFactory->expects($this->never())->method('createEntryIdentifier');
        $this->translationCache->expects($this->never())->method('set');
        $this->deepLCacheService->set("source", "target", "SL", "TL");
    }
}
