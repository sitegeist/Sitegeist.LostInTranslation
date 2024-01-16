<?php

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use InvalidArgumentException;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Scope("singleton")
 */
class DeepLAuthenticationKeyFactory
{
    /**
     * @var array{authenticationKey: string}
     * @Flow\InjectConfiguration(path="DeepLApi")
     */
    protected array $settings;

    /**
     * @Flow\Inject
     * @var DeepLCustomAuthenticationKeyService
     */
    protected $customAuthenticationKeyService;

    /**
     * @return DeepLAuthenticationKey
     */
    public function create(): DeepLAuthenticationKey
    {
        $customKey = $this->customAuthenticationKeyService->get();
        $settingsKey = $this->settings['authenticationKey'] ?? null;
        if (!isset($settingsKey) && !isset($customKey)) {
            throw new InvalidArgumentException('Empty strings are not allowed as authentication key');
        }
        return new DeepLAuthenticationKey($customKey ?? $settingsKey, !is_null($customKey));
    }
}
