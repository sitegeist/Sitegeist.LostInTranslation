<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\AppInfo;
use DeepL\DeepLException;
use DeepL\GlossaryEntries;
use DeepL\TextResult;
use DeepL\TranslateTextOptions;
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
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryLanguageKeys;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Infrastructure\Cache\TranslationCacheAdapter;
use Sitegeist\LostInTranslation\Utility\IgnoredTermsUtility;

/**
 * @Flow\Scope("singleton")
 */
class DeepLTranslationService implements TranslationServiceInterface
{
    /**
     * @var array{defaultOptions?: array<string,mixed>, ignoredTerms?:array<string,string>}
 */
    protected array $settings = [];

    protected ?LoggerInterface $logger = null;
    protected ?TranslationCacheAdapter $translationCacheAdapter = null;
    protected ?DeepLGlossaryIdService $glossaryIdService = null;

    public function __construct(
        private readonly DeeplClientFactory $deeplClientFactory,
        private readonly DeepLAuthenticationKeyFactory $deeplAuthenticationKeyFactory,
    ) {
    }

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function injectTranslationCacheAdapter(TranslationCacheAdapter $translationCacheAdapter): void
    {
        $this->translationCacheAdapter = $translationCacheAdapter;
    }

    public function injectDeepLGlossaryIdService(DeepLGlossaryIdService $glossaryIdService): void
    {
        $this->glossaryIdService = $glossaryIdService;
    }

    /**
     * @param array{DeepLApi: array{defaultOptions?: array<string,mixed>, ignoredTerms?:array<string,string>}} $settings
     * @return void
     */
    public function injectSettings(array $settings): void
    {
        $this->settings = $settings['DeepLApi'];
    }

    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array<string,string>
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        if ($sourceLanguage) {
            $glossaryId = $this->glossaryIdService?->findGlossaryId($sourceLanguage, $targetLanguage);
        } else {
            $glossaryId = null;
        }

        $cachedEntries = [];

        if ($this->translationCacheAdapter?->isEnabled()) {
            foreach ($texts as $i => $text) {
                if ($cachedValue = $this->translationCacheAdapter->get($text, $targetLanguage, $sourceLanguage)) {
                    $cachedEntries[$i] = $cachedValue;
                    unset($texts[$i]);
                }
            }
            if (empty($texts)) {
                return $cachedEntries;
            }
        }

        $client = $this->deeplClientFactory->createDeepLClient();

        $translateTextOptions = [
            $this->settings['defaultOptions'] ?? []
        ];

        if ($glossaryId) {
            $translateTextOptions[TranslateTextOptions::GLOSSARY] = $glossaryId;
        }

        // store keys and values separately for later reunion
        $keys = array_keys($texts);
        $values = array_values($texts);

        // wrap ignoredTerms
        if (isset($this->settings['ignoredTerms']) && count($this->settings['ignoredTerms']) > 0) {
            $valuesWithMaskedTerms = array_map(
                fn(string $text) => IgnoredTermsUtility::wrapIgnoredTerms($text, $this->settings['ignoredTerms']),
                $values
            );
        } else {
            $valuesWithMaskedTerms = $values;
        }

        try {
            /**
             * @var TextResult[]|TextResult $results
             */
            $results = $client->translateText(
                $valuesWithMaskedTerms,
                $sourceLanguage,
                $targetLanguage,
                $translateTextOptions
            );

            if ($results instanceof TextResult) {
                $results = [$results];
            }

            $translations = array_map(
                fn (TextResult $textResult) => IgnoredTermsUtility::unwrapIgnoredTerms($textResult->text),
                $results
            );

            $translationWithOriginalIndex = array_combine($keys, $translations);

            if ($this->translationCacheAdapter?->isEnabled()) {
                foreach ($translationWithOriginalIndex as $i => $translatedString) {
                    $originalString = $texts[$i];
                    $this->translationCacheAdapter->set($originalString, $translatedString, $targetLanguage, $sourceLanguage);
                }
            }

            $mergedTranslatedStrings = array_replace($translationWithOriginalIndex, $cachedEntries);
            ksort($mergedTranslatedStrings);
            return $mergedTranslatedStrings;
        } catch (DeepLException $e) {
            $this->logger?->critical('DeeplException caught: ' . $e->getMessage());
            return array_replace($texts, $cachedEntries);
        }
    }

    public function getStatus(): ApiStatus
    {
        try {
            $key = $this->deeplAuthenticationKeyFactory->createDeepLAuthenticationKey();
        } catch (\Exception) {
            return new ApiStatus(false, 0, 0, false, false, false);
        }

        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $usage = $client->getUsage();
            return new ApiStatus(true, $usage->character?->count ?? 0, $usage->character?->limit ?? 0, true, $key->isCustomKey, $key->isFree);
        } catch (DeepLException $exception) {
            return new ApiStatus(false, 0, 0, true, $key->isCustomKey, $key->isFree);
        }
    }

    public function getGlossaryLanguageKeys(): GlossaryLanguageKeys
    {
        $client = $this->deeplClientFactory->createDeepLClient();
        $pairs = $client->getGlossaryLanguages();
        $sourceLanguages = [];
        $targetLanguages = [];
        foreach ($pairs as $pair) {
            $sourceLanguages[$pair->sourceLang] = $pair->sourceLang;
            $targetLanguages[ $pair->targetLang] = $pair->targetLang;
        }
        return new GlossaryLanguageKeys(array_values($sourceLanguages), array_values($targetLanguages));
    }

    public function uploadGlossary(Glossary $glossary): ?string
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $info = $client->createGlossary(
                $glossary->getLabel(),
                $glossary->sourceLanguageKey,
                $glossary->targetLanguageKey,
                GlossaryEntries::fromEntries($glossary->getEntriesAsAssociativeArray())
            );
            return $info->glossaryId;
        } catch (DeepLException $exception) {
            $this->logger?->critical('DeeplException caught: ' . $exception->getMessage());
            return null;
        }
    }

    public function deleteGlossary(string $id): void
    {
        try {
            $client = $this->deeplClientFactory->createDeepLClient();
            $client->deleteGlossary($id);
            return;
        } catch (DeepLException $exception) {
            $this->logger?->critical('DeeplException caught: ' . $exception->getMessage());
            return;
        }
    }

    /**
     * @param  string      $text
     * @param  string      $targetLanguage
     * @param  string|null $sourceLanguage
     *
     * @return string
     */
    public static function getEntryIdentifier(string $text, string $targetLanguage, ?string $sourceLanguage = null): string
    {
        return sha1($text . $targetLanguage . $sourceLanguage);
    }
}
