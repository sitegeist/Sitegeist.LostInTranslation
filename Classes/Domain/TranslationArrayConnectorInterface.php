<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * @template TArray of array
 */
interface TranslationArrayConnectorInterface
{
    /**
     * @param TArray $array
     * @return array<non-empty-string, string>
     */
    public function extractTranslations(array $array): array;

    /**
     * @param TArray $array
     * @param array<non-empty-string, string> $translations
     * @return TArray
     */
    public function applyTranslations(array $array, array $translations): array;
}
