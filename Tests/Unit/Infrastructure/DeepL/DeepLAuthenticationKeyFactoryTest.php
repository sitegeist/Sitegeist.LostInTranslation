<?php

namespace Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKeyFactory;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;

class DeepLAuthenticationKeyFactoryTest extends UnitTestCase
{
    protected MockObject|DeepLCustomAuthenticationKeyService $customKeyService;
    public function setUp(): void
    {
        $this->customKeyService = $this->getAccessibleMock(DeepLCustomAuthenticationKeyService::class, ['get'], [], '', false);
    }

    /** @test */
    public function cachedCustomKeyOverridesConfiguredAuthenticationKey(): void
    {
        $this->customKeyService->method('get')->willReturn('cachedKey');


        $authenticationKey = $this->getFactory()->create();


        $this->assertEquals('cachedKey', $authenticationKey->__toString());
    }

    /** @test */
    public function canBeCreatedWithConfiguredAuthenticationKey(): void
    {
        $this->customKeyService->method('get')->willReturn(null);


        $authenticationKey = $this->getFactory()->create();


        $this->assertEquals('configuredKey', $authenticationKey->__toString());
    }

    protected function getFactory(): MockObject|DeepLAuthenticationKeyFactory
    {
        $factory = new DeepLAuthenticationKeyFactory();
        $this->inject($factory, 'customAuthenticationKeyService', $this->customKeyService);
        $this->inject($factory, 'settings', [
            'authenticationKey' => 'configuredKey'
        ]);
        return $factory;
    }
}
