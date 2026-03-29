<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

interface TranslationServiceInterface
{
    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @param string|null $formality 'less', 'more', 'default', 'prefer_less', 'prefer_more' or null if not specified
     * @return array<string,string>
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null, ?string $formality = null): array;

    public function getStatus(): ApiStatus;
}
