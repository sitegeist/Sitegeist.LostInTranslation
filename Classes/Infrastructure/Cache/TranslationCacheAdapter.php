<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\Cache;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;

class TranslationCacheAdapter
{
    /**
     * @var bool
     * @Flow\InjectConfiguration(path="DeepLApi.enableCache")
     */
    protected $enabled  = false;

    /**
     * @Flow\Inject
     * @var StringFrontend
     */
    protected $translationCache;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function get(string $sourceText, string $targetLanguage, ?string $sourceLanguage = null): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $entryIdentifier = $this->getEntryIdentifier($sourceText, $targetLanguage, $sourceLanguage);
        return $this->translationCache->get($entryIdentifier);
    }

    public function set(string $sourceText, string $targetText, $targetLanguage, ?string $sourceLanguage = null): void
    {
        if (!$this->enabled) {
            return;
        }
        $entryIdentifier = $this->getEntryIdentifier($sourceText, $targetLanguage, $sourceLanguage);
        $this->translationCache->set($entryIdentifier, $targetText);
    }

    private function getEntryIdentifier(string $sourceText, string $targetLanguage, ?string $sourceLanguage = null): string
    {
        return sha1($sourceText . $targetLanguage . $sourceLanguage ?? '-');
    }
}
