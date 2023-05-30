<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Http\Client\Browser;
use Neos\Flow\Http\Client\CurlEngine;
use Neos\Flow\Http\Client\CurlEngineException;
use Neos\Http\Factories\ServerRequestFactory;
use Neos\Http\Factories\StreamFactory;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

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
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        // store keys and values seperately for later reunion
        $keys = array_keys($texts);
        $values = array_values($texts);

        $deeplAuthenticationKey = new DeepLAuthenticationKey($this->settings['authenticationKey']);
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
                return $texts;
            }
            $translations = array_map(
                function ($part) {
                    return preg_replace('/(<ignore>|<\/ignore>)/i', '', $part['text']);
                },
                $returnedData['translations']
            );
            return array_combine($keys, $translations);
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
            return $texts;
        }
    }
}
