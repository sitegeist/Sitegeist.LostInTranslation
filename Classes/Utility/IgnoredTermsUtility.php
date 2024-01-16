<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

class IgnoredTermsUtility
{
    /**
     * @param string $string
     * @param string[]  $ignoredTerms
     *
     * @return string
     */
    public static function wrapIgnoredTerms(string $string, array $ignoredTerms): string
    {
        $stringWithWrappedIgnoredTerms = preg_replace('/(' . implode('|', $ignoredTerms) . ')/i', '<ignore>$1</ignore>', $string);
        return !is_null($stringWithWrappedIgnoredTerms) ? $stringWithWrappedIgnoredTerms : $string;
    }

    public static function unwrapIgnoredTerms(string $string): string
    {
        $stringWithUnwrappedIgnoredTerms = preg_replace('/(<ignore>|<\/ignore>)/i', '', $string);
        return !is_null($stringWithUnwrappedIgnoredTerms) ? $stringWithUnwrappedIgnoredTerms : $string;
    }
}
