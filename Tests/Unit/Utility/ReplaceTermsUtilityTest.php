<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\ReplaceTerm;
use Sitegeist\LostInTranslation\Utility\ReplaceTermsUtility;

class ReplaceTermsUtilityTest extends UnitTestCase
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
            ],
            [
                'term' => 'Kaiser Maximilian Prize',
                'translations' => [
                    'de' => 'Kaiser Maximilian Preis',
                    'fr' => 'Prix Kaiser Maximilian'
                ]
            ],
            [
                'term' => 'Kaiser Maximilian Prizes',
                'translations' => [
                    'de' => 'Kaiser Maximilian Preise',
                    'fr' => 'Prix Kaiser Maximilian'
                ]
            ]
        ];
        $ignoreTermsConfiguration = ['Neos.io', 'Code Q', 'Sitegeist', 'Kaiser Maximilian Prize'];

        return [
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'en',
                [
                    '0' => new ReplaceTerm('Kaiser Maximilian Prize', 'Kaiser Maximilian Prize'),
                    '1' => new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    '2' => new ReplaceTerm('Neos.io', 'Neos.io'),
                    '3' => new ReplaceTerm('Code Q', 'Code Q')
                ]
            ],
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'de',
                [
                    '0' => new ReplaceTerm('Kaiser Maximilian Prizes', 'Kaiser Maximilian Preise'),
                    '1' => new ReplaceTerm('Kaiser Maximilian Prize', 'Kaiser Maximilian Preis'),
                    '2' => new ReplaceTerm('ECB', 'EZB'),
                    '3' => new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    '4' => new ReplaceTerm('Neos.io', 'Neos.io'),
                    '5' => new ReplaceTerm('Code Q', 'Code Q'),
                ]
            ],
            [
                $ignoreTermsConfiguration,
                $replaceTermsConfiguration,
                'fr',
                [
                    '0' => new ReplaceTerm('Kaiser Maximilian Prizes', 'Prix Kaiser Maximilian'),
                    '1' => new ReplaceTerm('Kaiser Maximilian Prize', 'Prix Kaiser Maximilian'),
                    '2' => new ReplaceTerm('ECB', 'BCE'),
                    '3' => new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    '4' => new ReplaceTerm('Neos.io', 'Neos.io'),
                    '5' => new ReplaceTerm('Code Q', 'Code Q'),
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
        $termsToReplace = ReplaceTermsUtility::getTermsToReplace($ignoredTerms, $replaceTerms, $targetLanguage);
        $this->assertEquals($expectedArray, $termsToReplace);
        $this->assertEquals(array_values($expectedArray), array_values($termsToReplace));
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
                'Hallo, <name id="0">Sitegeist</name>!'
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
                    new ReplaceTerm('Code Q', 'Code Q'),
                    new ReplaceTerm('Code', 'Code')
                ],
                '<name id="0">Sitegeist</name> und <name id="2">Code Q</name> sind Agenturen für <name id="1">Neos.io</name>'
            ],
            [
                'Dipl.-Ing. Felix Gradinaru lädt zur Gala des Kaiser-Maximilian-Preises 2025. Auch bekannt als kaiser-maximilian-preis. Nicht zu verwechseln mit Kaiser Maximilian-Preis und Kaiser-Maximilian Preis!',
                [
                    new ReplaceTerm('Kaiser Maximilian Preises', 'Kaiser Maximilian Preises'),
                    new ReplaceTerm('Kaiser-Maximilian Preises', 'Kaiser-Maximilian Preises'),
                    new ReplaceTerm('Kaiser Maximilian-Preises', 'Kaiser Maximilian-Preises'),
                    new ReplaceTerm('Kaiser-Maximilian-Preises', 'Kaiser-Maximilian-Preises'),
                    new ReplaceTerm('Kaiser Maximilian Preis', 'Kaiser Maximilian Preis'),
                    new ReplaceTerm('Kaiser-Maximilian Preis', 'Kaiser-Maximilian Preis'),
                    new ReplaceTerm('Kaiser Maximilian-Preis', 'Kaiser Maximilian-Preis'),
                    new ReplaceTerm('Kaiser-Maximilian-Preis', 'Kaiser-Maximilian-Preis'),
                    new ReplaceTerm('Dipl.-Ing.', 'Dipl.-Ing.'),
                ],
                '<name id="8">Dipl.-Ing.</name> Felix Gradinaru lädt zur Gala des <name id="3">Kaiser-Maximilian-Preises</name> 2025. Auch bekannt als <name id="7">Kaiser-Maximilian-Preis</name>. Nicht zu verwechseln mit <name id="6">Kaiser Maximilian-Preis</name> und <name id="5">Kaiser-Maximilian Preis</name>!'
            ]
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
            [
                'Hallo, <name id="0">Sitegeist</name>!',
                [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ],
                'Hallo, Sitegeist!'
            ],
            [
                'Hallo, Sitegeis!', [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ],
                'Hallo, Sitegeis!'
            ],
            [
                '<name id="0">Sitegeist</name> und <name id="2">Code Q</name> sind Agenturen für <name id="1">Neos.io</name>', [
                    new ReplaceTerm('Sitegeist', 'Sitegeist'),
                    new ReplaceTerm('Neos.io', 'Neos.io'),
                    new ReplaceTerm('Code Q', 'Code Q')
                ], 'Sitegeist und Code Q sind Agenturen für Neos.io'
            ],
        ];
    }

    /**
     * @test
     * @dataProvider unwrapIgnoredTermsUnwrapsIgnoredTermsCorrectlyData
     *
     * @param  string  $string
     * @param  array<ReplaceTerm>  $replaceTerms
     * @param  string  $expectedString
     *
     * @return void
     */
    public function unwrapIgnoredTermsUnwrapsIgnoredTermsCorrectly(string $string, array $replaceTerms, string $expectedString): void
    {
        $unwrappedString = ReplaceTermsUtility::unwrapFromIgnoreTagInString($string, $replaceTerms);


        $this->assertEquals($expectedString, $unwrappedString);
    }
}
