<?php

namespace Sitegeist\LostInTranslation\Tests\Functional\Controller;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Cache\CacheManager;
use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Flow\Tests\FunctionalTestCase;
use Neos\Neos\Controller\Backend\ModuleController;
use Neos\Party\Domain\Model\Person;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Service\PartyService;
use Sitegeist\LostInTranslation\Package;

class LostInTranslationModuleControllerTest extends FunctionalTestCase
{
    protected StringFrontend $apiKeyCache;

    public function setUp(): void
    {
        parent::setUp();

        $cacheManager = $this->objectManager->get(CacheManager::class);
        $this->apiKeyCache = $cacheManager->getCache('Sitegeist_LostInTranslation_ApiKeyCache');

        $moduleController = $this->objectManager->get(ModuleController::class);

        $person = new Person();
        $person->setName(new PersonName("", "John", "", "Doe"));

        $securityContextMock = $this->getMockBuilder(Context::class)->getMock();
        $securityContextMock->method('getAccount')->willReturn(new Account());

        $partyServiceMock = $this->getMockBuilder(PartyService::class)->getMock();
        $partyServiceMock->method('getAssignedPartyOfAccount')->withAnyParameters()->willReturn($person);

        $this->inject($moduleController, 'securityContext', $securityContextMock);
        $this->inject($moduleController, 'partyService', $partyServiceMock);
    }

    /**
     * @test
     * @return void
     * @throws InfiniteRedirectionException
     */
    public function storeCustomKeyActionTest(): void
    {
        $fakeKey = "fakekey";
        $this->browser->request('http://localhost/neos/management/sitegeist_lostintranslation?moduleArguments%5B%40package%5D=sitegeist.lostintranslation&moduleArguments%5B%40controller%5D=lostintranslationmodule&moduleArguments%5B%40action%5D=storecustomkey&moduleArguments%5B%40format%5D=html', 'POST', [
            "moduleArguments" => [
                "key" => $fakeKey
            ]
        ]);
        $this->assertEquals($fakeKey, $this->apiKeyCache->get(Package::API_KEY_CACHE_ID));
    }

    /**
     * @test
     * @return void
     * @throws \Neos\Flow\Http\Client\InfiniteRedirectionException
     */
    public function removeCustomKeyActionTest(): void
    {
        $this->browser->request('http://localhost/neos/management/sitegeist_lostintranslation?moduleArguments%5B%40package%5D=sitegeist.lostintranslation&moduleArguments%5B%40controller%5D=lostintranslationmodule&moduleArguments%5B%40action%5D=removecustomkey&moduleArguments%5B%40format%5D=html&moduleArguments%5B%40subpackage%5D=', 'POST');
        $this->assertFalse($this->apiKeyCache->get(Package::API_KEY_CACHE_ID));
    }
}
