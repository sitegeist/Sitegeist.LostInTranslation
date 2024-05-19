<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Infrastructure\DeepL;

use GuzzleHttp\Psr7\Response;
use Mockery;
use Neos\Cache\Backend\TransientMemoryBackend;
use Neos\Cache\Exception;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Cache\Frontend\VariableFrontend;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngineException;
use Neos\Flow\Tests\UnitTestCase;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Http\Factories\StreamFactory;
use Neos\Http\Factories\UriFactory;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKey;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class DeepLTranslationServiceTest extends UnitTestCase
{
    protected MockObject|VariableFrontend $translationCache;
    protected MockObject|DeepLCustomAuthenticationKeyService $customKeyServiceMock;
    protected MockObject|LoggerInterface $loggerMock;
    protected MockObject|Browser $browserMock;

    public function setUp(): void
    {
        $this->translationCache = new VariableFrontend('Sitegeist_LostInTranslation_TranslationCache', new TransientMemoryBackend());
        $this->translationCache->initializeObject();
        $this->customKeyServiceMock = $this->getAccessibleMock(DeepLCustomAuthenticationKeyService::class, ['get'], [], '', false);
        $this->loggerMock = Mockery::mock(LoggerInterface::class);
        $this->browserMock = $this->getAccessibleMock(Browser::class, ['sendRequest'], [], '', false);
    }

    public static function translateWillCorrectlyTranslateTextsData(): array
    {
        return [
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['de_foo', 'de_bar', 'de_baz'],
                new Response(200, [], json_encode(['translations' => [['text' => 'de_foo'], ['text' => 'de_bar'], ['text' => 'de_baz']]]))
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'bar', 'baz'],
                new Response(200, [], 'somebrokenjson')
            ],
            [
                ['foo', 'bar'],
                'de',
                null,
                ['cached_de_foo', 'cached_de_bar'],
                null,
                [DeepLTranslationService::getEntryIdentifier('foo', 'de') => 'cached_de_foo', DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar']
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['de_foo', 'cached_de_bar', 'de_baz'],
                new Response(200, [], json_encode(['translations' => [0 => ['text' => 'de_foo'], 2 => ['text' => 'de_baz']]])),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar']
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'cached_de_bar', 'baz'],
                new Response(400),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar'],
                'Your DeepL API request was not well-formed. Please check the source and the target language in particular.'
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'cached_de_bar', 'baz'],
                new Response(403),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar'],
                'Your DeepL API credentials are either wrong, or you don\'t have access to the requested API.',
                'critical'
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'cached_de_bar', 'baz'],
                new Response(429),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar'],
                'You sent too many requests to the DeepL API.'
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'cached_de_bar', 'baz'],
                new Response(456),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar'],
                'You reached your DeepL API character limit. Upgrade your plan or wait until your quota is filled up again.'
            ],
            [
                ['foo', 'bar', 'baz'],
                'de',
                null,
                ['foo', 'cached_de_bar', 'baz'],
                new Response(599),
                [DeepLTranslationService::getEntryIdentifier('bar', 'de') => 'cached_de_bar'],
                'Unexpected status from Deepl API'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider translateWillCorrectlyTranslateTextsData
     *
     * @param array         $texts
     * @param string        $targetLanguage
     * @param string|null   $sourceLanguage
     * @param array         $expectedTranslatedTexts The translated texts that are expected to be returned by the service method
     * @param Response|null $response
     * @param array         $cachedTranslatedTexts   The translated texts that are currently stored in the cache
     * @param string|null   $expectedLoggerMessage
     * @param string        $expectedLoggerMethod
     *
     * @return void
     * @throws Exception
     */
    public function translateWillCorrectlyTranslateTexts(
        array $texts,
        string $targetLanguage,
        ?string $sourceLanguage,
        array $expectedTranslatedTexts,
        ?Response $response,
        array $cachedTranslatedTexts = [],
        string $expectedLoggerMessage = null,
        string $expectedLoggerMethod = 'warning'
    ): void
    {
        if ($expectedLoggerMessage) {
            $this->loggerMock->shouldReceive($expectedLoggerMethod)->once()->withSomeOfArgs($expectedLoggerMessage);
        }


        $service = $this->getService(['authenticationKey' => 'configuredKey']);
        $service->method('getDeeplAuthenticationKey')->willReturn(new DeepLAuthenticationKey('foobarbaz'));
        if ($response) {
            $this->browserMock->method('sendRequest')
                ->will(
                    $this->onConsecutiveCalls(
                        $this->throwException(new CurlEngineException()),
                        $response
                    )
                );
        }
        foreach($cachedTranslatedTexts as $identifier => $value) {
            $this->translationCache->set($identifier, $value);
        }

        $translatedTexts = $service->translate($texts, $targetLanguage, $sourceLanguage);


        $this->assertEquals($expectedTranslatedTexts, $translatedTexts);
    }

    public static function getApiStatusWorksCorrectlyData(): array
    {
        return [
            ['settingsKey', null, new Response(200, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 99, 999, true, false, false],
            ['settingsKey:fx', null, new Response(200, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 99, 999, true, false, true],
            ['settingsKey:fx', 'cachedKey', new Response(200, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 99, 999, true, true, false],
            ['settingsKey', 'cachedKey:fx', new Response(200, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 99, 999, true, true, true],
            ['settingsKey', 'cachedKey:fx', new Response(400, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 0, 0, true, true, true],
            [null, null, new Response(400, [], json_encode(['character_count' => 99, 'character_limit' => 999])), 0, 0, false, false, false],
        ];
    }

    /**
     * @test
     * @dataProvider getApiStatusWorksCorrectlyData
     *
     * @param string|null      $settingsKey
     * @param string|bool|null $customKey
     * @param Response         $usageResponse
     * @param int              $expectedCharacterCount
     * @param int              $expectedCharacterLimit
     * @param bool             $expectedHasSettingsKey
     * @param bool             $expectedHasCustomKey
     * @param bool             $expectedIsFree
     *
     * @return void
     */
    public function getApiStatusWorksCorrectly(string|null $settingsKey, string|null $customKey, Response $usageResponse, int $expectedCharacterCount, int $expectedCharacterLimit, bool $expectedHasSettingsKey, bool $expectedHasCustomKey, bool $expectedIsFree): void
    {
        $service = $this->getService(['authenticationKey' => $settingsKey]);
        $this->customKeyServiceMock->method('get')->willReturn($customKey);
        $this->browserMock->method('sendRequest')->willReturn($usageResponse);
        $validAuthenticationKey = ($customKey ?: null) ?? $settingsKey ?? null;
        if ($validAuthenticationKey) {
            $deepLAuthenticationKey = new DeepLAuthenticationKey($validAuthenticationKey);
            $service->method('getDeeplAuthenticationKey')->willReturn($deepLAuthenticationKey);
        } else {
            $service->method('getDeeplAuthenticationKey')->willThrowException(new \Exception());
        }

        $apiStatus = $service->getStatus();


        $this->assertEquals($expectedHasSettingsKey, $apiStatus->isHasSettingsKey(), 'hasSettingsKey');
        $this->assertEquals($expectedHasCustomKey, $apiStatus->isHasCustomKey(), 'hasCustomKey');
        $this->assertEquals($expectedIsFree, $apiStatus->isFreeApi(), 'isFreeApi');
        $this->assertEquals($expectedCharacterCount, $apiStatus->getCharacterCount(), 'characterCount');
        $this->assertEquals($expectedCharacterLimit, $apiStatus->getCharacterLimit(), 'characterLimit');
    }

    public function getService(array $overrideSettings = []): MockObject|DeepLTranslationService
    {
        $service = $this->getAccessibleMock(DeepLTranslationService::class, ['getBrowser', 'createServerRequest', 'getDeeplAuthenticationKey'], [], '', false);
        $this->inject($service, 'serverRequestFactory', new ServerRequestFactory(new UriFactory()));
        $this->inject($service, 'streamFactory', new StreamFactory());
        $this->inject($service, 'logger', $this->loggerMock);
        $this->inject($service, 'translationCache', $this->translationCache);
        $this->inject($service, 'customAuthenticationKeyService', $this->customKeyServiceMock);
        $this->inject($service, 'settings', array_merge_recursive([
                'baseUri' => 'https://api.deepl.com/v2/',
                'baseUriFree' => 'https://api-free.deepl.com/v2/',
                'defaultOptions' => [
                    'tag_handling' => 'xml',
                    'split_sentences' => 'nonewlines',
                    'preserve_formatting' => 1,
                    'formality' => 'default',
                    'ignore_tags' => 'ignore',
                ],
                'ignoredTerms' => [],
                'numberOfAttempts' => 2,
                'enableCache' => true,
            ], $overrideSettings)
        );
        $service->method('getBrowser')->willReturn($this->browserMock);

        return $service;
    }
}
