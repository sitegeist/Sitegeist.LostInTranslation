<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Utility\IgnoredTermsUtility;

class IgnoredTermsUtilityTest extends UnitTestCase
{
    public static function wrapIgnoredTermsWrapsIgnoredTermsCorrectlyData(): array
    {
        return [
            ['Hallo, Sitegeist!', ['Sitegeist', 'Neos.io', 'Code Q'], 'Hallo, <ignore>Sitegeist</ignore>!'],
            ['Hallo, Sitegeis!', ['Sitegeist', 'Neos.io', 'Code Q'], 'Hallo, Sitegeis!'],
            ['Sitegeist und Code Q sind Agenturen für Neos.io', ['Sitegeist', 'Neos.io', 'Code Q'], '<ignore>Sitegeist</ignore> und <ignore>Code Q</ignore> sind Agenturen für <ignore>Neos.io</ignore>'],
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
        $wrappedString = IgnoredTermsUtility::wrapIgnoredTerms($string, $ignoredTerms);


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
        $unwrappedString = IgnoredTermsUtility::unwrapIgnoredTerms($string);


        $this->assertEquals($expectedString, $unwrappedString);
    }
}
