<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\ReplaceTerm;
use Sitegeist\LostInTranslation\Utility\ReplaceTermsUtility;

class IgnoredTermsUtilityTest extends UnitTestCase
{
    public static function evaluateReplaceTermsArrayCreatesCorrectArrayData(): array
    {
        $replaceTermsConfiguration = [
            [
                'term' => 'ECB',
                'translations' => [
                    'de' => 'EZB',
                    'fr' => 'BCE'
                ]
            ]
        ];
        $ignoreTermsConfiguration = ['Sitegeist', 'Neos.io', 'Code Q'];

        return [
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'en',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ]
            ],
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'de',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q'),
                    new ReplaceTerm('ECB', 'EZB')
                ]
            ],
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'fr',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q'),
                    new ReplaceTerm('ECB', 'BCE')
                ]
            ],
        ];
    }

    /**
     * @test
     * @dataProvider evaluateReplaceTermsArrayCreatesCorrectArrayData
     */
    public function evaluateReplaceTermsArrayCreatesCorrectArray(array $ignoredTerms, array $replaceTerms, string $targetLanguage, array $expectedArray): void
    {
        $this->assertEquals($expectedArray, ReplaceTermsUtility::getTermsToReplace($ignoredTerms, $replaceTerms, $targetLanguage));
    }

    public static function wrapIgnoredTermsWrapsIgnoredTermsCorrectlyData(): array
    {
        return [
            [
                'Hallo, Sitegeist!',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ],
                'Hallo, <ignore>Sitegeist</ignore>!'
            ],
            [
                'Hallo, Sitegeis!',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ],
                'Hallo, Sitegeis!'
            ],
            [
                'Sitegeist und Code Q sind Agenturen für Neos.io',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ],
                '<ignore>Sitegeist</ignore> und <ignore>Code Q</ignore> sind Agenturen für <ignore>Neos.io</ignore>'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider wrapIgnoredTermsWrapsIgnoredTermsCorrectlyData
     *
     * @param string $string
     * @param array  $ignoredTerms
     * @param string $expectedString
     *
     * @return void
     */
    public function wrapIgnoredTermsWrapsIgnoredTermsCorrectly(string $string, array $ignoredTerms, string $expectedString): void
    {
        $wrappedString = ReplaceTermsUtility::replaceTermsAndWrapInIgnoreTagInString($string, $ignoredTerms);


        $this->assertEquals($expectedString, $wrappedString);
    }

    public static function unwrapIgnoredTermsUnwrapsIgnoredTermsCorrectlyData(): array
    {
        return [
            ['Hallo, <ignore>Sitegeist</ignore>!', 'Hallo, Sitegeist!'],
            ['Hallo, Sitegeis!', 'Hallo, Sitegeis!'],
            ['<ignore>Sitegeist</ignore> und <ignore>Code Q</ignore> sind Agenturen für <ignore>Neos.io</ignore>', 'Sitegeist und Code Q sind Agenturen für Neos.io'],
        ];
    }

    /**
     * @test
     * @dataProvider unwrapIgnoredTermsUnwrapsIgnoredTermsCorrectlyData
     *
     * @param string $string
     * @param string $expectedString
     *
     * @return void
     */
    public function unwrapIgnoredTermsUnwrapsIgnoredTermsCorrectly(string $string, string $expectedString): void
    {
        $unwrappedString = ReplaceTermsUtility::unwrapFromIgnoreTagInString($string);


        $this->assertEquals($expectedString, $unwrappedString);
    }
}
