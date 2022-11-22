<?php

namespace Sitegeist\LostInTranslation\Eel;

use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class TranslationHelper implements ProtectedContextAwareInterface
{
    /**
     * @Flow\Inject
     * @var DeepLTranslationService
     */
    protected $translationService;

    /**
     * @param  string  $text  A string to be translated
     * @param  string  $targetLanguage  The target language that should be translated to
     * @param  string|null  $sourceLanguage  Optional: the source language of the texts
     * @return string   The translated text
     */
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): string
    {
        return $this->translationService->translate([$text], $targetLanguage, $sourceLanguage)[0];
    }

    /**
     * @param  array  $texts  An array of strings to be translated
     * @param  string  $targetLanguage  The target language that should be translated to
     * @param  string|null  $sourceLanguage  Optional: the source language of the texts
     * @return array    An array with the translated texts and with the same indices from the input array
     */
    public function translateMultiple(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        return $this->translationService->translate($texts, $targetLanguage, $sourceLanguage);
    }

    /**
     * @inheritDoc
     */
    public function allowsCallOfMethod($methodName)
    {
        return true;
    }
}
