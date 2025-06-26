<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;

class DeepLCacheService
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

    /**
     * @Flow\Inject
     * @var DeepLCacheIdentifierFactory
     */
    protected $cacheIdentifierFactory;

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function get(string $sourceText, ?string $sourceLanguage, string $targetLanguage): ?string
    {
        if (!$this->enabled) {
            return null;
        }
        $entryIdentifier = $this->cacheIdentifierFactory->createEntryIdentifier($sourceText, $sourceLanguage, $targetLanguage);
        $result = $this->translationCache->get($entryIdentifier);
        return is_string($result) ? $result : null;
    }

    public function set(string $sourceText, string $targetText, ?string $sourceLanguage, string $targetLanguage): void
    {
        if (!$this->enabled) {
            return;
        }
        $entryIdentifier = $this->cacheIdentifierFactory->createEntryIdentifier($sourceText, $sourceLanguage, $targetLanguage);
        $this->translationCache->set($entryIdentifier, $targetText);
    }
}
