<?php

namespace Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Tests\UnitTestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLAuthenticationKeyFactory;

class DeepLAuthenticationKeyFactoryTest extends UnitTestCase
{
    protected MockObject|StringFrontend $apiKeyCache;
    public function setUp(): void
    {
        $this->apiKeyCache = $this->getAccessibleMock(StringFrontend::class, ['get'], [], '', false);
    }

    /** @test */
    public function cachedCustomKeyOverridesConfiguredAuthenticationKey(): void
    {
        $this->apiKeyCache->method('get')->willReturn('cachedKey');


        $authenticationKey = $this->getFactory()->create();


        $this->assertEquals('cachedKey', $authenticationKey->__toString());
    }

    /** @test */
    public function canBeCreatedWithConfiguredAuthenticationKey(): void
    {
        $this->apiKeyCache->method('get')->willReturn(false);


        $authenticationKey = $this->getFactory()->create();


        $this->assertEquals('configuredKey', $authenticationKey->__toString());
    }

    /**
     * @return MockObject|DeepLAuthenticationKeyFactory
     */
    protected function getFactory()
    {
        $factory = new DeepLAuthenticationKeyFactory();
        $this->inject($factory, 'apiKeyCache', $this->apiKeyCache);
        $this->inject($factory, 'settings', [
            'authenticationKey' => 'configuredKey'
        ]);
        return $factory;
    }
}
