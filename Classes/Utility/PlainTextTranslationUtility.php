<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNames;

class PlainTextTranslationUtility
{
    /**
     * @param array<non-empty-string, string> $properties
     * @return array<non-empty-string, string>
     */
    public static function encodePlainTextProperties(array $properties, TranslatablePropertyNames $translatableProperties): array
    {
        foreach ($properties as $name => $value) {
            if ($translatableProperties->isPlainText($name)) {
                $properties[$name] = htmlspecialchars(
                    $value,
                    ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1,
                    'UTF-8',
                    false
                );
            }
        }
        return $properties;
    }

    /**
     * @param array<non-empty-string, string> $properties
     * @return array<non-empty-string, string>
     */
    public static function decodePlainTextProperties(array $properties, TranslatablePropertyNames $translatableProperties): array
    {
        foreach ($properties as $name => $value) {
            if ($translatableProperties->isPlainText($name)) {
                $properties[$name] = htmlspecialchars_decode($value, ENT_QUOTES | ENT_XML1);
            }
        }
        return $properties;
    }
}
