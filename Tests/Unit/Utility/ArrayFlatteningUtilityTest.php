<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Utility;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

class ArrayFlatteningUtilityTest  extends UnitTestCase {

    public function provideExamples(): \Generator
    {
        yield 'empty array' => [
            [],
            [],
            '.'
        ];

        yield 'simple array' => [
            ['foo' => 'bar', 'bar' => 'baz'],
            ['foo' => 'bar', 'bar' => 'baz'],
            '.'
        ];

        yield 'nested array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam']],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam'],
            '.'
        ];

        yield 'mixed array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam'], 'baz' => 'bam'],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam', 'baz' => 'bam'],
            '.'
        ];

        yield 'deeply nested mixed array' => [
            ['foo' => ['bar' => 'baz', 'baz' => 'bam'], 'bar' => ['baz' => 'bam'], 'baz' => 'bam'],
            ['foo.bar' => 'baz', 'foo.baz' => 'bam', 'bar.baz' => 'bam', 'baz' => 'bam'],
            '.'
        ];
    }

    /**
     * @dataProvider provideExamples
     * @param array<string, string|array<string,string>> $enflated
     * @param array<string, string> $deflated
     * @param string $seperator
     * @return void
     */
    public function testArrayDeflation(array $enflated, array $deflated, string $seperator): void
    {
        $this->assertEquals($deflated, ArrayFlatteningUtility::deflate($enflated, $seperator));
    }

    /**
     * @dataProvider provideExamples
     * @param array<string, string|array<string,string>> $enflated
     * @param array<string, string> $deflated
     * @param string $seperator
     * @return void
     */
    public function testArrayEnflation(array $enflated, array $deflated, string $seperator): void
    {
        $this->assertEquals($enflated, ArrayFlatteningUtility::enflate($deflated, $seperator));
    }
}
