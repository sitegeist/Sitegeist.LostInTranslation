<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation;

use Neos\ContentRepository\Domain\Model\Workspace;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Package\Package as BasePackage;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Flow\Persistence\Doctrine\PersistenceManager;
use Neos\Neos\Fusion\Cache\ContentCacheFlusher;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

/**
 * The Neos Package
 */
class Package extends BasePackage
{
    const API_KEY_CACHE_ID = 'lostInTranslationApiKey';

    /**
     * @param Bootstrap $bootstrap The current bootstrap
     * @return void
     */
    public function boot(Bootstrap $bootstrap)
    {
        $dispatcher = $bootstrap->getSignalSlotDispatcher();
        $dispatcher->connect(Context::class, 'afterAdoptNode', NodeTranslationService::class, 'afterAdoptNode', false);
        $dispatcher->connect(Workspace::class, 'beforeNodePublishing', NodeTranslationService::class, 'collectNodesToBeTranslated', false);
        $dispatcher->connect(PersistenceManager::class, 'allObjectsPersisted', NodeTranslationService::class, 'translateNodes', false);
    }
}
