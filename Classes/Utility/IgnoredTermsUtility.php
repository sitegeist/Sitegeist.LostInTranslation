<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

class IgnoredTermsUtility
{
    public static function wrapIgnoredTerms(string $string, array $ignoredTerms): string
    {
        return preg_replace('/(' . implode('|', $ignoredTerms) . ')/i', '<ignore>$1</ignore>', $string);
    }

    public static function unwrapIgnoredTerms(string $string): string
    {
        return preg_replace('/(<ignore>|<\/ignore>)/i', '', $string);
    }
}
