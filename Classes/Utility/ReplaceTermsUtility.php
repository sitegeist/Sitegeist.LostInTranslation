<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

use Neos\Flow\Configuration\Exception;
use Sitegeist\LostInTranslation\Domain\ReplaceTerm;

class ReplaceTermsUtility
{
    /**
     * @throws Exception
     */
    public static function getTermsToReplace(array $ignoreTerms, array $replaceTerms, string $targetLanguage): array
    {
        $replaceTermsArray = [];
        // First we define that ignored terms should be replaced with themselves
        // This is to ensure backwards compatibility
        foreach ($ignoreTerms as $ignoreTerm) {
            $replaceTermsArray[$ignoreTerm] = new ReplaceTerm($ignoreTerm, $ignoreTerm);
        }
        // Then we add the terms that should be replaced with a translation
        foreach ($replaceTerms as $replaceTerm) {
            if (!isset($replaceTerm['translations'][$targetLanguage])) {
                continue;
            }
            if (!is_string($replaceTerm['translations'][$targetLanguage])) {
                throw new Exception(sprintf('The replace term for "%s" must be a string', $replaceTerm), 1735818017971);
            }
            $replaceTermsArray[$replaceTerm['term']] = new ReplaceTerm($replaceTerm['term'], $replaceTerm['translations'][$targetLanguage]);
        }
        return array_values($replaceTermsArray);
    }

    /**
     * @param string $string
     * @param ReplaceTerm[]  $replaceTerms
     *
     * @return string
     */
    public static function replaceTermsAndWrapInIgnoreTagInString(string $string, array $replaceTerms): string
    {
        $patterns = array_map(static function (ReplaceTerm $term) {
            return '/(' . $term->getOriginal() . ')/i';
        }, $replaceTerms);
        $replacements = array_map(static function (ReplaceTerm $term) {
            return '<ignore>' . $term->getTranslation() . '</ignore>';
        }, $replaceTerms);
        $stringWithReplacedTermsAndIgnoreTags = preg_replace($patterns, $replacements, $string);
        return !is_null($stringWithReplacedTermsAndIgnoreTags) ? $stringWithReplacedTermsAndIgnoreTags : $string;
    }

    public static function unwrapFromIgnoreTagInString(string $string): string
    {
        $stringWithoutIgnoreTags = preg_replace('/(<ignore>|<\/ignore>)/i', '', $string);
        return !is_null($stringWithoutIgnoreTags) ? $stringWithoutIgnoreTags : $string;
    }
}
