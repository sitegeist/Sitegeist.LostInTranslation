<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Fusion\View\FusionView;
use Neos\Neos\Controller\Module\AbstractModuleController;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Infrastructure\DeepL\DeepLTranslationService;
use Sitegeist\LostInTranslation\Package;

class LostInTranslationModuleController extends AbstractModuleController
{
    /**
     * @var DeepLTranslationService
     * @Flow\Inject
     */
    protected $translationService;

    /**
     * @var StringFrontend
     * @Flow\Inject
     */
    public $apiKeyCache;

    /**
     * @var FusionView
     */
    protected $view;


    public function indexAction()
    {
        $status = $this->translationService->getStatus();
        $this->view->assign('status', $status);
    }

    public function setCustomKeyAction()
    {
    }

    public function storeCustomKeyAction(string $key)
    {
        $this->apiKeyCache->set(Package::API_KEY_CACHE_ID, $key);
        $this->forward('index');
    }

    public function removeCustomKeyAction()
    {
        $this->apiKeyCache->remove(Package::API_KEY_CACHE_ID);
        $this->forward('index');
    }
}
