<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

/**
 * The resolved DeepL source/target language identifiers for a translation (source dimension → target dimension).
 */
final readonly class DeeplLanguagePair
{
    public function __construct(
        public string $sourceLanguage,
        public string $targetLanguage,
    ) {
    }
}
