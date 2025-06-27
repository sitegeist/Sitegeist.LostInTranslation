<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Utility;

use Neos\Flow\Configuration\Exception;
use Sitegeist\LostInTranslation\Domain\ReplaceTerm;

class ReplaceTermsUtility
{
    /**
     * @param array<string>  $ignoreTerms
     * @param array<array{term: string, translations: array<string, string>}>  $replaceTerms
     * @param string $targetLanguage
     *
     * @return array<ReplaceTerm>
     * @throws Exception
     */
    public static function getTermsToReplace(array $ignoreTerms, array $replaceTerms, string $targetLanguage): array
    {
        // First we add the terms that should be replaced with a translation
        // We sort all terms by length to ensure that the longest terms are replaced first
        $sortedReplaceTerms = $replaceTerms;
        usort($sortedReplaceTerms, static function (array $replaceTermA, array $replaceTermB) {
            return strlen($replaceTermB['term']) - strlen($replaceTermA['term']);
        });
        $replaceTermsArray = [];
        foreach ($sortedReplaceTerms as $replaceTerm) {
            if (!isset($replaceTerm['translations'][$targetLanguage])) {
                continue;
            }
            if (!is_string($replaceTerm['translations'][$targetLanguage])) {
                throw new Exception(sprintf('The replace term for "%s" must be a string', $replaceTerm['term']), 1735818017971);
            }
            $replaceTermsArray[$replaceTerm['term']] = new ReplaceTerm($replaceTerm['term'], $replaceTerm['translations'][$targetLanguage]);
        }
        // Secondly we define that ignored terms should be replaced with themselves
        // This is to ensure backwards compatibility
        // We again sort the terms by length to ensure that the longest terms are replaced first
        $sortedIgnoreTerms = $ignoreTerms;
        usort($sortedIgnoreTerms, static function (string $ignoreTermA, string $ignoreTermB) {
            return strlen($ignoreTermB) - strlen($ignoreTermA);
        });
        $ignoreTermsArray = [];
        foreach ($sortedIgnoreTerms as $ignoreTerm) {
            // If the term is already in the replace terms array, we skip it
            if (isset($replaceTermsArray[$ignoreTerm])) {
                continue;
            }
            $ignoreTermsArray[$ignoreTerm] = new ReplaceTerm($ignoreTerm, $ignoreTerm);
        }
        return array_merge(array_values($replaceTermsArray), array_values($ignoreTermsArray));
    }

    /**
     * @param string $string
     * @param array<string, ReplaceTerm>  $replaceTerms
     *
     * @return string
     */
    public static function replaceTermsAndWrapInIgnoreTagInString(string $string, array $replaceTerms): string
    {
        $stringWithReplacedTermsAndIgnoreTags = $string;
        foreach ($replaceTerms as $sha1 => $term) {
            $pattern = '/<name\b[^>]*>.*?<\/name>(*SKIP)(*FAIL)|' . preg_quote($term->getOriginal(), '/') . '/i';
            // @phpstan-ignore argument.type
            $stringWithReplacedTermsAndIgnoreTags = preg_replace($pattern, sprintf('<name id="%s">%s</name>', $sha1, $term->getOriginal()), $stringWithReplacedTermsAndIgnoreTags);
        }
        return !is_null($stringWithReplacedTermsAndIgnoreTags) ? $stringWithReplacedTermsAndIgnoreTags : $string;
    }

    /**
     * @param  string  $string
     * @param  array<ReplaceTerm>  $replaceTerms
     * @return string
     */
    public static function unwrapFromIgnoreTagInString(string $string, array $replaceTerms): string
    {
        // @phpstan-ignore return.type
        return preg_replace_callback(
            '/<name id="([^"]+)">([^<]+)<\/name>/',
            static function ($matches) use ($replaceTerms) {
                $sha1 = $matches[1];
                /** @var ReplaceTerm $replaceTerm */
                $replaceTerm = $replaceTerms[$sha1] ?? null;
                return $replaceTerm->getTranslation() ?? $matches[0];
            },
            $string
        );
    }
}
