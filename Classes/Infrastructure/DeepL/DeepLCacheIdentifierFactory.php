<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;

class DeepLCacheIdentifierFactory
{
    public function createEntryIdentifier(string $sourceText, ?string $sourceLanguage, string $targetLanguage): string
    {
        return sha1($sourceText . $targetLanguage . ($sourceLanguage ?? '-'));
    }
}
