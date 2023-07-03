<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

interface TranslationServiceInterface
{
    /**
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array;

    public function getStatus(): ApiStatus;
}
