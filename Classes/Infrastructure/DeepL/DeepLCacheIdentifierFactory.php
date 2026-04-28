<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

class DeepLCacheIdentifierFactory
{
    public function createEntryIdentifier(string $sourceText, ?string $sourceLanguage, string $targetLanguage): string
    {
        return sha1($sourceText . $targetLanguage . ($sourceLanguage ?? '-'));
    }
}
