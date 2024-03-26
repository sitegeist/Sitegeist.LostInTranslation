<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLCustomAuthenticationKeyService;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;

class LostInTranslationModuleController extends AbstractModuleController
{
    /**
     * @var DeepLTranslationService
     * @Flow\Inject
     */
    protected $translationService;

    /**
     * @Flow\Inject
     * @var DeepLCustomAuthenticationKeyService
     */
    protected $customAuthenticationKeyService;

    /**
     * @var FusionView
     */
    protected $view;


    public function indexAction(): void
    {
        $status = $this->translationService->getStatus();
        $this->view->assign('status', $status);
    }

    public function setCustomKeyAction(): void
    {
    }

    public function storeCustomKeyAction(string $key): void
    {
        $this->customAuthenticationKeyService->set($key);
        $this->forward('index');
    }

    public function removeCustomKeyAction(): void
    {
        $this->customAuthenticationKeyService->remove();
        $this->forward('index');
    }
}
