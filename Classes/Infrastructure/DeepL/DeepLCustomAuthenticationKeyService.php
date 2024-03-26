<?php

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Cache\Frontend\StringFrontend;

class DeepLCustomAuthenticationKeyService
{
    private const API_KEY_CACHE_ID = 'lostInTranslationApiKey';
    /**
     * @var StringFrontend
     */
    protected $apiKeyCache;

    public function get(): ?string
    {
        return $this->apiKeyCache->get(self::API_KEY_CACHE_ID) ?: null;
    }

    public function set(string $key): void
    {
        $this->apiKeyCache->set(self::API_KEY_CACHE_ID, $key);
    }

    public function remove(): void
    {
        $this->apiKeyCache->remove(self::API_KEY_CACHE_ID);
    }
}
