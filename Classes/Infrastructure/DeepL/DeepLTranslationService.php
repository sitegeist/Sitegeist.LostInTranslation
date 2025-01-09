<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\GlossaryEntries;
use DeepL\TranslateTextOptions;
use Neos\Flow\Annotations as Flow;
use DeepL\DeepLException;
use DeepL\TextResult;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryLanguageKeys;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;
use Sitegeist\LostInTranslation\Utility\ReplaceTermsUtility;

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
    protected ?DeepLCacheService $translationCache = null;
    protected ?DeepLGlossaryService $glossaryService = null;

    public function __construct(
        private readonly DeeplClientFactory $deeplClientFactory,
        private readonly DeepLAuthenticationKeyFactory $deeplAuthenticationKeyFactory,
    ) {
    }

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function injectTranslationCache(DeepLCacheService $translationCache): void
    {
        $this->translationCache = $translationCache;
    }

    public function injectDeepLGlossaryService(DeepLGlossaryService $glossaryService): void
    {
        $this->glossaryService = $glossaryService;
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
     * @var array
     */
    protected $replaceTermsByLanguage = [];

    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array<string,string>
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        // deepl api does throw critical errors when 'en' or 'pt' is used
        // this prevents that by defaulting to the most likely option
        if (strtolower($targetLanguage) === 'en') {
            $targetLanguage = 'en-GB';
        }
        if (strtolower($targetLanguage) === 'pt') {
            $targetLanguage = 'pt-PT';
        }

        if (
            array_key_exists('defaultOptions', $this->settings)
            && is_array($this->settings['defaultOptions'])
        ) {
            $translateTextOptions = $this->settings['defaultOptions'];
        } else {
            $translateTextOptions = [];
        }

        if ($sourceLanguage) {
            $glossaryId = $this->glossaryService?->findGlossaryId($sourceLanguage, $targetLanguage);
            if ($glossaryId) {
                $translateTextOptions[TranslateTextOptions::GLOSSARY] = $glossaryId;
            }
        }

        $cachedEntries = [];

        if ($this->translationCache?->isEnabled()) {
            foreach ($texts as $i => $text) {
                if ($cachedValue = $this->translationCache->get($text, $sourceLanguage, $targetLanguage)) {
                    $cachedEntries[$i] = $cachedValue;
                    unset($texts[$i]);
                }
            }
            if (empty($texts)) {
                return $cachedEntries;
            }
        }

        $client = $this->deeplClientFactory->createDeepLClient();

        // store keys and values separately for later reunion
        $keys = array_keys($texts);
        $values = array_values($texts);

        // wrap replaceTerms
        $replaceTerms = $this->getReplaceTermsForLanguage($targetLanguage);
        if (isset($this->settings['ignoredTerms']) && count($this->settings['ignoredTerms']) > 0) {
            $valuesWithMaskedTerms = array_map(
                fn(string $text) => ReplaceTermsUtility::replaceTermsAndWrapInIgnoreTagInString($text, $replaceTerms),
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
                fn (TextResult $textResult) => ReplaceTermsUtility::unwrapFromIgnoreTagInString($textResult->text, $replaceTerms),
                $results
            );

            $translationWithOriginalIndex = array_combine($keys, $translations);

            if ($this->translationCache?->isEnabled()) {
                foreach ($translationWithOriginalIndex as $i => $translatedString) {
                    $originalString = $texts[$i];
                    $this->translationCache->set($originalString, $translatedString, $sourceLanguage, $targetLanguage);
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
            return new ApiStatus(true, $usage->character?->count ?? 0, $usage->character?->limit ?? 0, $key->isSettingKey, $key->isCustomKey, $key->isFree);
        } catch (DeepLException) {
            return new ApiStatus(false, 0, 0, $key->isSettingKey, $key->isCustomKey, $key->isFree);
        }
    }

    protected function getReplaceTermsForLanguage(string $language): array
    {
        if (!isset($this->replaceTermsByLanguage[$language])) {
            $this->replaceTermsByLanguage[$language] = ReplaceTermsUtility::getTermsToReplace($this->settings['ignoredTerms'], $this->settings['replaceTerms'], $language);
        }

        return $this->replaceTermsByLanguage[$language];
    }
}
