<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Infrastructure\DeepL;

use DeepL\DeepLClient;
use DeepL\DeepLException;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
use DeepL\Usage as UsageResult;
use Neos\Cache\Exception;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKey;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKeyFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeeplClientFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCacheService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLGlossaryService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class DeepLTranslationServiceTest extends UnitTestCase
{
    protected TranslationServiceInterface $translationService;
    protected DeeplClientFactory $mockDeeplClientFactory;
    protected DeepLClient $mockDeeplClient;
    protected DeepLAuthenticationKeyFactory $mockDeeplAuthenticationKeyFactory;

    public function setUp(): void
    {
        $this->mockDeeplClientFactory = $this->createMock(DeeplClientFactory::class);
        $this->mockDeeplClient = $this->createMock(DeepLClient::class);
        $this->mockDeeplClientFactory->expects(self::any())->method('createDeepLClient')->willReturn($this->mockDeeplClient);

        $this->mockDeeplAuthenticationKeyFactory = $this->createMock(DeepLAuthenticationKeyFactory::class);

        $this->translationService = new DeeplTranslationService(
            $this->mockDeeplClientFactory,
            $this->mockDeeplAuthenticationKeyFactory
        );
    }

    public static function translateWillCorrectlyTranslateTextsDataProvider(): \Generator
    {
        # if a single text ist translated it is returns as TextResult by DeepL
        yield "single_text" => [
            ['en_foo'],
            'de',
            null,
            ['de_foo'],
            new TextResult('de_foo', 'en', 6),
        ];

        # multiple texts are returned as array of text results
        yield "multiple_texts" => [
            ['en_foo', 'en_bar', 'en_baz'],
            'de',
            null,
            ['de_foo', 'de_bar', 'de_baz'],
            [
                new TextResult('de_foo', 'en', 6),
                new TextResult('de_bar', 'en', 6),
                new TextResult('de_baz', 'en', 6)
            ]
        ];
    }

    /**
     * @test
     * @dataProvider translateWillCorrectlyTranslateTextsDataProvider
     *
     * @param string[] $expectedTranslatedTexts The translated texts that are expected to be returned by the service method
     * @param null|TextResult|TextResult[] $response
     */
    public function translateWillCorrectlyTranslateTexts(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $expectedTranslatedTexts,
        null|TextResult|array $response,
    ): void {
        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with($texts, $sourceLanguage, $targetLanguage)
            ->willReturn($response);

        $translatedTexts = $this->translationService->translate(
            $texts,
            $targetLanguage,
            $sourceLanguage
        );

        $this->assertEquals($expectedTranslatedTexts, $translatedTexts);
    }

    /**
     * @test
     */
    public function translateUsesGlossaryIfFound(): void
    {
        $mockGlossaryService = $this->createMock(DeepLGlossaryService::class);
        $mockGlossaryService
            ->expects(self::once())
            ->method('findGlossaryId')
            ->with('en', 'de')
            ->willReturn('en_de_glossary');

        $this->translationService->injectDeepLGlossaryService($mockGlossaryService);

        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with(['en_foo', 'en_bar', 'en_baz'], 'en', 'de', [TranslateTextOptions::GLOSSARY => 'en_de_glossary'])
            ->willReturn([
                new TextResult('de_foo', 'en', 6),
                new TextResult('de_bar', 'en', 6),
                new TextResult('de_baz', 'en', 6)
            ]);

        $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz'],
            'de',
            'en'
        );
    }

    /**
     * @test
     */
    public function translateWorksIfNoGlossaryIsFound(): void
    {
        $mockGlossaryService = $this->createMock(DeepLGlossaryService::class);
        $mockGlossaryService
            ->expects(self::once())
            ->method('findGlossaryId')
            ->with('en', 'de')
            ->willReturn(null);

        $this->translationService->injectDeepLGlossaryService($mockGlossaryService);

        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with(['en_foo', 'en_bar', 'en_baz'], 'en', 'de', [])
            ->willReturn(
                [
                    new TextResult('de_foo', 'en', 6),
                    new TextResult('de_bar', 'en', 6),
                    new TextResult('de_baz', 'en', 6)
                ]
            );

        $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz'],
            'de',
            'en'
        );
    }

    /**
     * @test
     */
    public function translatePassesTranslateOptionsToDeepLClient(): void
    {
        $this->translationService->injectSettings(
            [
                'DeepLApi' => [
                    'defaultOptions' => [
                        'suppe' => 'ist gut'
                    ]
                ]
            ]
        );

        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with(['en_foo', 'en_bar', 'en_baz'], 'en', 'de', ['suppe' => 'ist gut'])
            ->willReturn(
                [
                    new TextResult('de_foo', 'en', 6),
                    new TextResult('de_bar', 'en', 6),
                    new TextResult('de_baz', 'en', 6)
                ]
            );

        $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz'],
            'de',
            'en'
        );
    }

    /**
     * @test
     */
    public function translateMasksAndUnmasksIgnoredTerms(): void
    {
        $this->translationService->injectSettings(
            [
                'DeepLApi' => [
                    'ignoredTerms' => ['suppe', 'nudel']
                ]
            ]
        );

        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with(['die <ignore>suppe</ignore> schmeckt', 'text <ignore>nudel</ignore>', '<ignore>nudel</ignore> text', 'other'], 'en', 'de')
            ->willReturn(
                [
                    new TextResult('DE: die <ignore>suppe</ignore> schmeckt', 'en', 6),
                    new TextResult('DE: text <ignore>nudel</ignore>', 'en', 6),
                    new TextResult('DE: <ignore>nudel</ignore> text', 'en', 6),
                    new TextResult('DE: other', 'en', 6)
                ]
            );

        $translated = $this->translationService->translate(
            ['die suppe schmeckt', 'text nudel', 'nudel text', 'other'],
            'de',
            'en'
        );

        $this->assertEquals(
            $translated,
            ['DE: die suppe schmeckt', 'DE: text nudel', 'DE: nudel text', 'DE: other'],
        );
    }

    /**
     * @test
     */
    public function translateUsesCacheIfPresent(): void
    {
        $mockTranslationCache = $this->createMock(DeepLCacheService::class);
        $mockTranslationCache->expects(self::any())->method('isEnabled')->willReturn(true);

        // caches are requested for each text
        $expectedGetCount = self::exactly(4);
        $mockTranslationCache
            ->expects($expectedGetCount)
            ->method('get')
            ->willReturnCallback(
                function (string $sourceText, ?string $sourceLanguage, string $targetLanguage,) {
                    $this->assertEquals('de', $targetLanguage);
                    $this->assertEquals('en', $sourceLanguage);
                    return match ($sourceText) {
                        'en_foo' => 'de_foo',
                        'en_bar' => null,
                        'en_baz' => null,
                        'en_bam' => 'de_bam',
                        default => $this->fail('wtf')
                    };
                }
            );


        // only the non cached results are requested from deepl
        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->with(['en_bar', 'en_baz'], 'en', 'de')
            ->willReturn([
                new TextResult('de_bar', 'en', 3),
                new TextResult('de_baz', 'en', 3)
            ]);

        // results are stored in cache
        $expectedSetCount = self::exactly(2);
        $mockTranslationCache
            ->expects($expectedSetCount)
            ->method('set')
            ->willReturnCallback(
                fn(string $sourceText, string $targetText, ?string $sourceLanguage, string $targetLanguage) => match ($expectedSetCount->getInvocationCount()) {
                    1 => $this->assertEquals(['en_bar', 'de_bar', 'de', 'en'], [$sourceText, $targetText, $targetLanguage, $sourceLanguage]),
                    2 => $this->assertEquals(['en_baz', 'de_baz', 'de', 'en'], [$sourceText, $targetText, $targetLanguage, $sourceLanguage]),
                    default => $this->fail('wtf')
                }
            );

        $this->translationService->injectTranslationCache($mockTranslationCache);
        $translatedTexts = $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz', 'en_bam'],
            'de',
            'en'
        );

        $this->assertEquals(['de_foo', 'de_bar', 'de_baz', 'de_bam'], $translatedTexts);
    }

    /**
     * @test
     */
    public function translateDoesNotCallDeepLIfAllWasCached(): void
    {
        $mockDeeplTranslationCache = $this->createMock(DeepLCacheService::class);
        $mockDeeplTranslationCache->expects(self::any())->method('isEnabled')->willReturn(true);

        // cache knows all the requested texts
        $expectedGetCount = self::exactly(4);
        $mockDeeplTranslationCache
            ->expects($expectedGetCount)
            ->method('get')
            ->willReturnCallback(
                function (string $sourceText, ?string $sourceLanguage, string $targetLanguage) {
                    $this->assertEquals('de', $targetLanguage);
                    $this->assertEquals('en', $sourceLanguage);
                    return match ($sourceText) {
                        'en_foo' => 'de_foo',
                        'en_bar' => 'de_bar',
                        'en_baz' => 'de_baz',
                        'en_bam' => 'de_bam',
                        default => $this->fail('wtf')
                    };
                }
            );

        // no cache store and no api call
        $mockDeeplTranslationCache->expects(self::never())->method('set');
        $this->mockDeeplClient->expects(self::never())->method('translateText');

        $this->translationService->injectTranslationCache($mockDeeplTranslationCache);
        $translatedTexts = $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz', 'en_bam'],
            'de',
            'en'
        );

        $this->assertEquals(['de_foo', 'de_bar', 'de_baz', 'de_bam'], $translatedTexts);
    }

    /**
     * @test
     */
    public function translateReturnsTheInputWhenDeeplThrowsException(): void
    {
        $mockDeeplTranslationCache = $this->createMock(DeepLCacheService::class);
        $mockDeeplTranslationCache->expects(self::any())->method('isEnabled')->willReturn(true);

        // cache knows all the requested texts
        $expectedGetCount = self::exactly(4);
        $mockDeeplTranslationCache
            ->expects($expectedGetCount)
            ->method('get')
            ->willReturnCallback(
                function (string $sourceText, ?string $sourceLanguage, string $targetLanguage) {
                    $this->assertEquals('de', $targetLanguage);
                    $this->assertEquals('en', $sourceLanguage);
                    return match ($sourceText) {
                        'en_foo' => 'de_foo',
                        'en_bar' => 'de_bar',
                        'en_baz' => null,
                        'en_bam' => null,
                        default => $this->fail('wtf')
                    };
                }
            );

        // no cache store and no api call
        $mockDeeplTranslationCache->expects(self::never())->method('set');
        $this->mockDeeplClient
            ->expects(self::once())
            ->method('translateText')
            ->willThrowException(new DeepLException('something went sideways'));

        $this->translationService->injectTranslationCache($mockDeeplTranslationCache);
        $translatedTexts = $this->translationService->translate(
            ['en_foo', 'en_bar', 'en_baz', 'en_bam'],
            'de',
            'en'
        );

        $this->assertEquals(['de_foo', 'de_bar', 'en_baz', 'en_bam'], $translatedTexts);
    }

    public static function getApiStatusWorksCorrectlyDataProvider(): \Generator
    {
        yield [
            new DeepLAuthenticationKey('foo:fx', false, true),
            new UsageResult('{"character_count": 99, "character_limit": 999}'),
            new ApiStatus(true, 99, 999, true, false, true, false)
        ];

        yield [
            new DeepLAuthenticationKey('foo', true, true),
            new UsageResult('{"character_count": 99, "character_limit": 999}'),
            new ApiStatus(true, 99, 999, true, true, false, false)
        ];

        yield [
            new DeepLAuthenticationKey('foo', false, true),
            new UsageResult('{"character_count": 9999, "character_limit": 999}'),
            new ApiStatus(true, 9999, 999, true, false, false, true)
        ];
    }

    /**
     * @dataProvider getApiStatusWorksCorrectlyDataProvider
     * @test
     */
    public function getApiStatusWorksCorrectly(DeepLAuthenticationKey $apiKey, UsageResult $usage, ApiStatus $expectedStatus): void
    {
        $this->mockDeeplAuthenticationKeyFactory
            ->expects(self::any())
            ->method('createDeepLAuthenticationKey')
            ->willReturn($apiKey);

        $this->mockDeeplClient
            ->expects(self::any())
            ->method('getUsage')
            ->willReturn($usage);

        $apiStatus = $this->translationService->getStatus();

        $this->assertEquals($expectedStatus, $apiStatus);
    }

    /**
     * @test
     */
    public function getApiStatusWhenAuthKeyFactoryThrowsException(): void
    {
        $this->mockDeeplAuthenticationKeyFactory
            ->expects(self::once())
            ->method('createDeepLAuthenticationKey')
            ->willThrowException(new \Exception('something went sideways'));

        $apiStatus = $this->translationService->getStatus();

        $this->assertEquals(new ApiStatus(
            false,
            0,
            0,
            false,
            false,
            false,
            false
        ), $apiStatus);
    }

    /**
     * @test
     */
    public function getApiStatusWhenDeepLThrowsException(): void
    {
        $this->mockDeeplAuthenticationKeyFactory
            ->expects(self::once())
            ->method('createDeepLAuthenticationKey')
            ->willReturn(
                new DeepLAuthenticationKey(
                    'foo:fx',
                    false,
                    true
                )
            );

        $this->mockDeeplClient
            ->expects(self::once())
            ->method('getUsage')
            ->willThrowException(new DeepLException('something went wrong'));

        $apiStatus = $this->translationService->getStatus();

        $this->assertEquals(new ApiStatus(
            false,
            0,
            0,
            true,
            false,
            true,
            false,
        ), $apiStatus);
    }
}
