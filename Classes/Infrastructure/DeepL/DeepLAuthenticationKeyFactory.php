<?php

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Package;

/**
 * @Flow\Scope("singleton")
 */
class DeepLAuthenticationKeyFactory
{
    /**
     * @var array
     * @Flow\InjectConfiguration(path="DeepLApi")
     */
    protected $settings;

    /**
     * @var StringFrontend
     */
    protected $apiKeyCache;

    /**
     * @return DeepLAuthenticationKey
     */
    public function create(): DeepLAuthenticationKey
    {
        $customKey = $this->apiKeyCache->get(Package::API_KEY_CACHE_ID) ?: null;
        $settingsKey = $this->settings['authenticationKey'] ?? null;
        return new DeepLAuthenticationKey($customKey ?? $settingsKey);
    }
}
