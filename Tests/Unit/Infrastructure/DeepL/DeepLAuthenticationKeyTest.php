<?php

namespace Sitegeist\LostInTranslation\Tests\Unit\Infrastructure\DeepL;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKey;

class DeepLAuthenticationKeyTest extends UnitTestCase
{
    /** @test */
    public function emptyAuthenticationKeyThrowsInvalidArgumentException(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Empty strings are not allowed as authentication key');


        new DeepLAuthenticationKey('');
    }

    public static function constructorTestParameters(): array
    {
        return [
            ['foobar', 'foobar', false],
            ['foobar:fx', 'foobar:fx', true]
        ];
    }

    /**
     * @test
     * @dataProvider constructorTestParameters
     *
     * @param string $authenticationKey
     * @param string $expectedAuthenticationKey
     * @param bool   $expectedIsFree
     *
     * @return void
     */
    public function canBeConstructedCorrectly(string $authenticationKey, string $expectedAuthenticationKey, bool $expectedIsFree): void
    {
        $authenticationKeyObject = new DeepLAuthenticationKey($authenticationKey);


        $this->assertEquals($expectedIsFree, $authenticationKeyObject->isFree());
        $this->assertEquals($expectedAuthenticationKey, $authenticationKeyObject->getAuthenticationKey());
        $this->assertEquals($authenticationKey, $authenticationKeyObject->__toString());
    }
}
