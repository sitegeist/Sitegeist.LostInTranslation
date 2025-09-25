<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * @template T of object
 */
interface TranslationConnectorInterface
{
    /**
     * @param T $object
     * @return array<non-empty-string, string>
     */
    public static function extractTranslations(object $object): array;

    /**
     * @param T $object
     * @param array<non-empty-string, string> $translations
     * @return T
     */
    public static function applyTranslations(object $object, array $translations): object;
}
