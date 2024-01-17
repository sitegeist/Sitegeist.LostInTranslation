<?php

namespace Sitegeist\LostInTranslation\Tests\Functional\Controller;

use Neos\Flow\Http\Client\InfiniteRedirectionException;
use Neos\Flow\Security\Account;
use Neos\Flow\Security\Context;
use Neos\Neos\Controller\Backend\ModuleController;
use Neos\Party\Domain\Model\Person;
use Neos\Party\Domain\Model\PersonName;
use Neos\Party\Domain\Service\PartyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Tests\Functional\AbstractFunctionalTestCase;

class LostInTranslationModuleControllerTest extends AbstractFunctionalTestCase
{
    protected DeepLCustomAuthenticationKeyService $customKeyService;

    public function setUp(): void
    {
        parent::setUp();

        $this->customKeyService = $this->objectManager->get(DeepLCustomAuthenticationKeyService::class);

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
            ],
            '__csrfToken' => $this->securityContext->getCsrfProtectionToken()
        ]);
        $this->assertEquals($fakeKey, $this->customKeyService->get());
    }

    /**
     * @test
     * @return void
     * @throws \Neos\Flow\Http\Client\InfiniteRedirectionException
     */
    public function removeCustomKeyActionTest(): void
    {
        $this->browser->request('http://localhost/neos/management/sitegeist_lostintranslation?moduleArguments%5B%40package%5D=sitegeist.lostintranslation&moduleArguments%5B%40controller%5D=lostintranslationmodule&moduleArguments%5B%40action%5D=removecustomkey&moduleArguments%5B%40format%5D=html&moduleArguments%5B%40subpackage%5D=', 'POST', [
            '__csrfToken' => $this->securityContext->getCsrfProtectionToken()
        ]);
        $this->assertNull($this->customKeyService->get());
    }
}
