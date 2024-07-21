<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\AppInfo;
use DeepL\TextResult;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use Exception;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Annotations\InjectConfiguration;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\CurlEngineException;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Http\Factories\StreamFactory;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Utility\IgnoredTermsUtility;

/**
 * @Flow\Scope("singleton")
 */
class DeepLTranslationService implements TranslationServiceInterface
{

    protected const INTERNAL_GLOSSARY_KEY_SEPARATOR = '-';

    /**
     * @var mixed[]
     * @Flow\InjectConfiguration(path="DeepLApi")
     */
    protected $settings;

    #[InjectConfiguration(path: "DeepLApi.glossary.languagePairs", package: "Sitegeist.LostInTranslation")]
    protected array $languagePairs;

    /**
     * @Flow\Inject
     * @var DeepLCustomAuthenticationKeyService
     */
    protected $customAuthenticationKeyService;

    /**
     * @var StringFrontend
     */
    protected $translationCache;

    /**
     * @Flow\Inject
     * @var DeepLAuthenticationKeyFactory
     */
    protected $authenticationKeyFactory;

    protected string $baseUri;
    protected string $authenticationKey;

    public function __construct(
        protected readonly LoggerInterface $logger,
        protected readonly ServerRequestFactory $serverRequestFactory,
        protected readonly StreamFactory $streamFactory,
    ) {}

    public function initializeObject(): void
    {
        $deeplAuthenticationKey = $this->getDeeplAuthenticationKey();
        $this->baseUri = $deeplAuthenticationKey->isFree ? $this->settings['baseUriFree'] : $this->settings['baseUri'];
        $this->authenticationKey = $deeplAuthenticationKey->authenticationKey;
    }

    protected function getBaseRequest(string $method, string $path): RequestInterface
    {
        return $this->serverRequestFactory->createServerRequest($method, $this->baseUri . $path)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', sprintf('DeepL-Auth-Key %s', $this->authenticationKey))
            ;
    }

    protected function getTranslateRequest(): RequestInterface
    {
        return $this->getBaseRequest('POST', 'translate')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ;
    }

    protected function getStatusRequest(): RequestInterface
    {
        return $this->getBaseRequest('POST', 'usage')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ;
    }

    protected function getGlossaryLanguagePairsRequest(): RequestInterface
    {
        return $this->getBaseRequest('GET', 'glossary-language-pairs');
    }

    protected function getGlossariesRequest(): RequestInterface
    {
        return $this->getBaseRequest('GET', 'glossaries');
    }

    protected function getDeleteGlossaryRequest(string $glossaryId): RequestInterface
    {
        return $this->getBaseRequest('DELETE', "glossaries/$glossaryId");
    }

    protected function getCreateGlossaryRequest(): RequestInterface
    {
        return $this->getBaseRequest('POST', 'glossaries')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ;
    }

    /**
     * @throws ClientExceptionInterface
     */
    protected function sendRequest(RequestInterface $request): ResponseInterface
    {
        $browser = new Browser();
        $engine = new CurlEngine();
        $engine->setOption(CURLOPT_TIMEOUT, 0);
        $browser->setRequestEngine($engine);
        return $browser->sendRequest($request);
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function sendGetRequest(RequestInterface $request): array
    {
        $response = $this->sendRequest($request);
        if ($response->getStatusCode() === 200) {
            return json_decode($response->getBody()->getContents(), true);
        } else {
            $this->handleApiErrorResponse($response);
        }
    }

    /**
     * @throws Exception
     */
    protected function handleApiErrorResponse(ResponseInterface $response): void
    {
        $content = json_decode($response->getBody()->getContents(), true);
        $detail = (is_array($content) && isset($content['detail']) ? $content['detail'] : null);
        $code = $response->getStatusCode();
        $reason = $response->getReasonPhrase();
        $message = "DeepL API error, HTTP Status $code ($reason)" . ($detail ? ": $detail" : '');
        $this->logger->error($message);
        throw new Exception($message);
    }

    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array<string,string>
     * @throws ClientExceptionInterface
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        $isCacheEnabled = $this->settings['enableCache'] ?? false;

        $cachedEntries = [];

        if ($isCacheEnabled) {
            foreach ($texts as $i => $text) {
                $entryIdentifier = self::getEntryIdentifier($text, $targetLanguage, $sourceLanguage);
                if ($this->translationCache->has($entryIdentifier)) {
                    $cachedEntries[$i] = $this->translationCache->get($entryIdentifier);
                    unset($texts[$i]);
                }
            }

            if (empty($texts)) {
                return $cachedEntries;
            }
        }

        // store keys and values separately for later reunion
        $keys = array_keys($texts);
        $values = array_values($texts);

        // request body ... this has to be done manually because of the non php ish format
        // with multiple text arguments
        $body = http_build_query($this->settings['defaultOptions']);
        if ($sourceLanguage) {
            $body .= '&source_lang=' . urlencode($sourceLanguage);
        }
        $body .= '&target_lang=' . urlencode($targetLanguage);
        foreach ($values as $part) {
            // All ignored terms will be wrapped in a <ignored> tag
            // which will be ignored by DeepL
            if (isset($this->settings['ignoredTerms']) && count($this->settings['ignoredTerms']) > 0) {
                $part = IgnoredTermsUtility::wrapIgnoredTerms($part, $this->settings['ignoredTerms']);
            }

            $body .= '&text=' . urlencode($part);
        }

        // the DeepL API is not consistent here - the "translate" endpoint requires the locale
        // for some languages, while the glossary can only handle pure languages - no locales -
        // so we extract the raw language from the configured languages that are used for "translate"
        [$glossarySourceLanguage] = explode('-', $sourceLanguage);
        [$glossaryTargetLanguage] = explode('-', $targetLanguage);
        $glossaryId = $this->getGlossaryId($glossarySourceLanguage, $glossaryTargetLanguage);
        if ($glossaryId !== null) {
            $body .= '&glossary_id=' . urlencode($glossaryId);
        }

        $apiRequest = $this
            ->getTranslateRequest()
            ->withBody($this->streamFactory->createStream($body))
        ;

        $browser = new Browser();
        $engine = new CurlEngine();
        $engine->setOption(CURLOPT_TIMEOUT, 0);
        $browser->setRequestEngine($engine);

        $apiResponse = null;
        $attempt = 0;
        $maximumAttempts = $this->settings['numberOfAttempts'];
        do {
            $attempt++;
            try {
                $apiResponse = $browser->sendRequest($apiRequest);
                break;
            } catch (CurlEngineException) {
                if ($attempt === $maximumAttempts) {
                    return $texts;
                }

                sleep(1);
                continue;
            }
        } while ($attempt <= $maximumAttempts);

        if ($apiResponse->getStatusCode() == 200) {
            $returnedData = json_decode($apiResponse->getBody()->getContents(), true);
            if (is_null($returnedData)) {
                return array_replace($texts, $cachedEntries);
            }

            $translations = array_map(
                function ($part) {
                    return IgnoredTermsUtility::unwrapIgnoredTerms($part['text']);
                },
                $returnedData['translations']
            );

            $translationWithOriginalIndex = array_combine($keys, $translations);

            if ($isCacheEnabled) {
                foreach ($translationWithOriginalIndex as $i => $translatedString) {
                    $originalString = $texts[$i];
                    $this->translationCache->set(self::getEntryIdentifier($originalString, $targetLanguage, $sourceLanguage), $translatedString);
                }
            }

            $mergedTranslatedStrings = array_replace($translationWithOriginalIndex, $cachedEntries);
            ksort($mergedTranslatedStrings);

            return $mergedTranslatedStrings;
        } else {
            if ($apiResponse->getStatusCode() === 403) {
                $this->logger->critical('Your DeepL API credentials are either wrong, or you don\'t have access to the requested API.');
            } elseif ($apiResponse->getStatusCode() === 429) {
                $this->logger->warning('You sent too many requests to the DeepL API.');
            } elseif ($apiResponse->getStatusCode() === 456) {
                $this->logger->warning('You reached your DeepL API character limit. Upgrade your plan or wait until your quota is filled up again.');
            } elseif ($apiResponse->getStatusCode() === 400) {
                $this->logger->warning('Your DeepL API request was not well-formed. Please check the source and the target language in particular.', [
                    'sourceLanguage' => $sourceLanguage,
                    'targetLanguage' => $targetLanguage
                ]);
            } else {
                $this->logger->warning('Unexpected status from Deepl API', ['status' => $apiResponse->getStatusCode()]);
            }

            return array_replace($texts, $cachedEntries);
        }
    }

    /**
     * @throws ClientExceptionInterface
     */
    public function getStatus(): ApiStatus
    {
        $hasSettingsKey =  $this->settings['authenticationKey'] ? true : false;
        $hasCustomKey = !is_null($this->customAuthenticationKeyService->get());

        try {
            $deeplAuthenticationKey = $this->getDeeplAuthenticationKey();

            $apiRequest = $this->getStatusRequest();

            $browser = new Browser();
            $engine = new CurlEngine();
            $engine->setOption(CURLOPT_TIMEOUT, 0);
            $browser->setRequestEngine($engine);

            $apiResponse = $browser->sendRequest($apiRequest);

            if ($apiResponse->getStatusCode() == 200) {
                $json = json_decode($apiResponse->getBody()->getContents(), true);
                return new ApiStatus(true, $json['character_count'], $json['character_limit'], $hasSettingsKey, $hasCustomKey, $deeplAuthenticationKey->isFree);
            } else {
                return new ApiStatus(false, 0, 0, $hasSettingsKey, $hasCustomKey, $deeplAuthenticationKey->isFree);
            }
        } catch (\Exception $exception) {
            return new ApiStatus(false, 0, 0, $hasSettingsKey, $hasCustomKey, false);
        }
    }

    protected function getDeeplAuthenticationKey(): DeepLAuthenticationKey
    {
        return $this->authenticationKeyFactory->create();
    }

    /**
     * @param  string      $text
     * @param  string      $targetLanguage
     * @param  string|null $sourceLanguage
     *
     * @return string
     */
    public static function getEntryIdentifier(string $text, string $targetLanguage, string $sourceLanguage = null): string
    {
        return sha1($text . $targetLanguage . $sourceLanguage);
    }

    /**
     * @param string      $endpoint
     *
     * @param string      $method
     * @param string|null $body
     *
     * @return ServerRequestInterface
     */
    protected function createRequest(
        string $endpoint,
        string $method = 'GET',
        string $body = null
    ): ServerRequestInterface {
        $deeplAuthenticationKey = $this->getDeeplAuthenticationKey();
        $baseUri = $deeplAuthenticationKey->isFree ? $this->settings['baseUriFree'] : $this->settings['baseUri'];
        $request = $this->serverRequestFactory->createServerRequest($method, $baseUri . $endpoint)
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', sprintf('DeepL-Auth-Key %s', $deeplAuthenticationKey->authenticationKey))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded');

        if ($body) {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        return $request;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    protected function getDeepLLanguagePairs(): array
    {
        $request = $this->getGlossaryLanguagePairsRequest();
        return $this->sendGetRequest($request)['supported_languages'];
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function getGlossaries(): array
    {
        $request = $this->getGlossariesRequest();
        return $this->sendGetRequest($request)['glossaries'];
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function getGlossaryId(string $sourceLanguage, string $targetLanguage): string|null
    {
        $requestedInternalKey = $this->getInternalGlossaryKey($sourceLanguage, $targetLanguage);
        $glossaries = $this->getGlossaries();
        foreach ($glossaries as $glossary) {
            if (!$glossary['ready']) {
                continue;
            }
            $currentInternalKey = $this->getInternalGlossaryKey($glossary['source_lang'], $glossary['target_lang']);
            if ($currentInternalKey === $requestedInternalKey) {
                return $glossary['glossary_id'];
            }
        }
        return null;
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function deleteGlossary(string $glossaryId): void
    {
        $request = $this->getDeleteGlossaryRequest($glossaryId);
        $response = $this->sendRequest($request);
        if ($response->getStatusCode() !== 204) {
            $this->handleApiErrorResponse($response);
        }
    }

    /**
     * @throws ClientExceptionInterface
     * @throws Exception
     */
    public function createGlossary(string $body): void
    {
        $bodyStream = $this->streamFactory->createStream($body);
        $request = $this->getCreateGlossaryRequest();
        $request = $request->withBody($bodyStream);

        $response = $this->sendRequest($request);
        if ($response->getStatusCode() !== 201) {
            $this->handleApiErrorResponse($response);
        }
    }

    public function getInternalGlossaryKey(string $sourceLangauge, string $targetLangauge): string
    {
        return strtoupper($sourceLangauge) . self::INTERNAL_GLOSSARY_KEY_SEPARATOR . strtoupper($targetLangauge);
    }

    /**
     * @return string[]
     */
    public function getLanguagesFromInternalGlossaryKey(string $internalGlossaryKey): array
    {
        list($sourceLangauge, $targetLangauge) = explode(self::INTERNAL_GLOSSARY_KEY_SEPARATOR, $internalGlossaryKey);
        return [$sourceLangauge, $targetLangauge];
    }

    /**
     * Only return configured language pairs that are supported by the DeepL API.
     * If $limitToLanguages is provided we also return the paired languages to the provided ones
     * in case they are missing.
     *
     * @throws ClientExceptionInterface
     */
    public function getLanguagePairs(array|null $limitToLanguages = null): array
    {
        $languagePairs = [];

        $limitToLanguagesUpdated = $limitToLanguages;
        $checkForLimitToLanguagesUpdate = false;
        $apiSource = null;
        $apiTarget = null;
        $configuredPairs = $this->languagePairs;
        $apiPairs = $this->getDeepLLanguagePairs();

        foreach ($configuredPairs as $configuredPair) {
            $configuredSource = $configuredPair['source'];
            $configuredTarget = $configuredPair['target'];

            if (
                is_array($limitToLanguages)
                && !in_array($configuredSource, $limitToLanguages)
                && !in_array($configuredTarget, $limitToLanguages)
            ) {
                continue;
            }

            $internalKeyFromConfiguredPair = $this->getInternalGlossaryKey($configuredSource, $configuredTarget);
            foreach ($apiPairs as $apiPair) {
                $apiSource = strtoupper($apiPair['source_lang']);
                $apiTarget = strtoupper($apiPair['target_lang']);
                $internalKeyFromApiPair = $this->getInternalGlossaryKey($apiSource, $apiTarget);
                if ($internalKeyFromConfiguredPair === $internalKeyFromApiPair) {
                    $languagePairs[] = $configuredPair;
                    $checkForLimitToLanguagesUpdate = true;
                    break;
                }
            }

            if ($checkForLimitToLanguagesUpdate && is_array($limitToLanguages)) {
                if (in_array($apiSource, $limitToLanguages) && !in_array($apiTarget, $limitToLanguagesUpdated)) {
                    $limitToLanguagesUpdated[] = $apiTarget;
                } elseif (in_array($apiTarget, $limitToLanguages) && !in_array($apiSource, $limitToLanguagesUpdated)) {
                    $limitToLanguagesUpdated[] = $apiSource;
                }
                $checkForLimitToLanguagesUpdate = false;
            }

        }

        return [$languagePairs, $limitToLanguagesUpdated];
    }

}
