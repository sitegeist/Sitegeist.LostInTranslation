<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

class ArrayFlatteningUtilityTest extends UnitTestCase
{
    public function provideExamples(): \Generator
    {
        yield 'empty array' => [
            [],
            []
        ];

        yield 'simple array' => [
            ['foo' => 'bar', 'bar' => 'baz'],
            ['foo' => 'bar', 'bar' => 'baz']
        ];

        yield 'nested array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam']],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam']
        ];

        yield 'mixed array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam'], 'baz' => 'bam'],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam', 'baz' => 'bam']
        ];

        yield 'nested mixed array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam'], 'baz' => 'bam'],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam', 'baz' => 'bam']
        ];

        yield 'nested with . in subkeys' => [
            ['foo' => ['bar.baz' => "bam", 'bar.bam' => 'blah'], 'bar' => ['baz.bam' => 'blah'], 'baz' => 'bam'],
            ['foo.bar.baz' => 'bam', 'foo.bar.bam' => 'blah', 'bar.baz.bam' => 'blah', 'baz' => 'bam']
        ];
    }

    /**
     * @dataProvider provideExamples
     * @param array<string, string|array<string,string>> $enflated
     * @param array<string, string> $deflated
     * @param string $seperator
     * @return void
     */
    public function testArrayDeflation(array $enflated, array $deflated): void
    {
        $this->assertEquals($deflated, ArrayFlatteningUtility::deflate($enflated));
    }

    /**
     * @dataProvider provideExamples
     * @param array<string, string|array<string,string>> $enflated
     * @param array<string, string> $deflated
     * @param string $seperator
     * @return void
     */
    public function testArrayEnflation(array $enflated, array $deflated): void
    {
        $this->assertEquals($enflated, ArrayFlatteningUtility::enflate($deflated));
    }
}
