<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\AppInfo;
use DeepL\TextResult;
use DeepL\Translator;
use DeepL\TranslatorOptions;
use DeepL\Usage;
use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\CurlEngineException;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Http\Factories\StreamFactory;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Package;

/**
 * @Flow\Scope("singleton")
 */
class DeepLTranslationService implements TranslationServiceInterface
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="DeepLApi")
     */
    protected $settings;

    /**
     * @Flow\Inject
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * @Flow\Inject
     * @var ServerRequestFactory
     */
    protected $serverRequestFactory;

    /**
     * @Flow\Inject
     * @var StreamFactory
     */
    protected $streamFactory;

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $apiKeyCache;

    /**
     * @var StringFrontend
     */
    protected $translationCache;

    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        $isCacheEnabled = $this->settings['enableCache'] ?? false;

        $cachedEntries = [];

        if ($isCacheEnabled) {
            foreach ($texts as $i => $text) {
                $entryIdentifier = $this->getEntryIdentifier($text, $targetLanguage, $sourceLanguage);
                if ($this->translationCache->has($entryIdentifier)) {
                    $cachedEntries[$i] = $this->translationCache->get($entryIdentifier);
                    unset($texts[$i]);
                }
            }

            if (empty($texts)) {
                return $cachedEntries;
            }
        }

        // store keys and values seperately for later reunion
        $keys = array_keys($texts);
        $values = array_values($texts);

        $deeplAuthenticationKey = $this->getDeeplAuthenticationKey();
        $baseUri = $deeplAuthenticationKey->isFree() ? $this->settings['baseUriFree'] : $this->settings['baseUri'];

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
                $part = preg_replace('/(' . implode('|', $this->settings['ignoredTerms']) . ')/i', '<ignore>$1</ignore>', $part);
            }

            $body .= '&text=' . urlencode($part);
        }

        $apiRequest = $this->serverRequestFactory->createServerRequest('POST', $baseUri . 'translate')
            ->withHeader('Accept', 'application/json')
            ->withHeader('Authorization', sprintf('DeepL-Auth-Key %s', $deeplAuthenticationKey->getAuthenticationKey()))
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($this->streamFactory->createStream($body));

        $browser = new Browser();
        $engine = new CurlEngine();
        $engine->setOption(CURLOPT_TIMEOUT, 0);
        $browser->setRequestEngine($engine);

        $attempt = 0;
        $maximumAttempts = $this->settings['numberOfAttempts'];
        do {
            $attempt++;
            try {
                $apiResponse = $browser->sendRequest($apiRequest);
                break;
            } catch (CurlEngineException $e) {
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
                    return preg_replace('/(<ignore>|<\/ignore>)/i', '', $part['text']);
                },
                $returnedData['translations']
            );

            $translationWithOriginalIndex = array_combine($keys, $translations);

            if ($isCacheEnabled) {
                foreach ($translationWithOriginalIndex as $i => $translatedString) {
                    $originalString = $texts[$i];
                    $this->translationCache->set($this->getEntryIdentifier($originalString, $targetLanguage, $sourceLanguage), $translatedString);
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

    public function getStatus(): ApiStatus
    {
        $hasSettingsKey =  $this->settings['authenticationKey'] ? true : false;
        $hasCustomKey = $this->apiKeyCache->has(Package::API_KEY_CACHE_ID);

        try {
            $deeplAuthenticationKey = $this->getDeeplAuthenticationKey();
            $baseUri = $deeplAuthenticationKey->isFree() ? $this->settings['baseUriFree'] : $this->settings['baseUri'];

            $apiRequest = $this->serverRequestFactory->createServerRequest('POST', $baseUri . 'usage')
                ->withHeader('Accept', 'application/json')
                ->withHeader('Authorization', sprintf('DeepL-Auth-Key %s', $deeplAuthenticationKey->getAuthenticationKey()))
                ->withHeader('Content-Type', 'application/x-www-form-urlencoded');

            $browser = new Browser();
            $engine = new CurlEngine();
            $engine->setOption(CURLOPT_TIMEOUT, 0);
            $browser->setRequestEngine($engine);

            $apiResponse = $browser->sendRequest($apiRequest);


            if ($apiResponse->getStatusCode() == 200) {
                $json = json_decode($apiResponse->getBody()->getContents(), true);
                return new ApiStatus(true, $json['character_count'], $json['character_limit'], $hasSettingsKey, $hasCustomKey, $deeplAuthenticationKey->isFree());
            } else {
                return new ApiStatus(false, 0, 0, $hasSettingsKey, $hasCustomKey, $deeplAuthenticationKey->isFree());
            }
        } catch (\Exception $exception) {
            return new ApiStatus(false, 0, 0, $hasSettingsKey, $hasCustomKey, false);
        }
    }

    protected function getDeeplAuthenticationKey(): DeepLAuthenticationKey
    {
        $customKey = $this->apiKeyCache->get(Package::API_KEY_CACHE_ID) ?: null;
        $settingsKey = $this->settings['authenticationKey'] ?? null;
        return new DeepLAuthenticationKey($customKey ?? $settingsKey);
    }

    /**
     * @param  string  $text
     * @param  string  $targetLanguage
     * @param  string|null  $sourceLanguage
     * @return string
     */
    protected function getEntryIdentifier(string $text, string $targetLanguage, string $sourceLanguage = null): string
    {
        return sha1($text . $targetLanguage . $sourceLanguage);
    }
}
