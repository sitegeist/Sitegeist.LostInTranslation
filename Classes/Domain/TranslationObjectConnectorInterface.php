<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * @template T
 */
interface TranslationObjectConnectorInterface {

    /**
     * @param T $object
     * @return array<string, string>
     */
    public static function extractTranslations(object $object): array;

    /**
     * @param T $object
     * @param array<string, string> $translations
     * @return T
     */
    public static function applyTranslations(object $object, array $translations): object;
}
