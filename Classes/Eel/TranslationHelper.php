<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Eel;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\Eel\ProtectedContextAwareInterface;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Controller\CreateContentContextTrait;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Result;
use Sitegeist\LostInTranslation\Domain\CollectionComparison\Comparator;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

// TODO: still used?
class TranslationHelper implements ProtectedContextAwareInterface
{
     /**
     * @Flow\Inject
     * @var DeepLTranslationService
     */
    protected $translationService;

    /**
     * @param string $text A string to be translated
     * @param string $targetLanguage The target language that should be translated to
     * @param string|null $sourceLanguage Optional: the source language of the texts
     * @return string The translated text
     */
    public function translate(string $text, string $targetLanguage, ?string $sourceLanguage = null): string
    {
        return $this->translationService->translate(['text' => $text], $targetLanguage, $sourceLanguage)['text'];
    }

    /**
     * @param array<string, string> $texts An array of strings to be translated
     * @param string $targetLanguage The target language that should be translated to
     * @param string|null $sourceLanguage Optional: the source language of the texts
     * @return array<string, string> An array with the translated texts and with the same indices from the input array
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
