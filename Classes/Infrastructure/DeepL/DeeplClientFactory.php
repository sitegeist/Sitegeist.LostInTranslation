<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Neos\Flow\Annotations as Flow;
use DeepL\DeepLClient;
use DeepL\TranslatorOptions;
use Psr\Http\Client\ClientInterface;

class DeeplClientFactory
{
    #[Flow\Inject]
    protected ClientInterface $httpClient;

    #[Flow\Inject]
    protected DeepLAuthenticationKeyFactory $authenticationKeyFactory;

    /**
     * @var mixed[]
     */
    protected array $settings;

    /**
     * @param mixed[] $settings
     * @return void
     */
    public function injectSettings(array $settings): void
    {
         $this->settings = $settings['DeepLApi'];
    }

    public function createDeepLClient(): DeepLClient
    {
        $key = $this->authenticationKeyFactory->createDeepLAuthenticationKey();

        $options = [
            TranslatorOptions::HTTP_CLIENT => $this->httpClient
        ];

        if ($retries = $this->settings['numberOfAttempts'] ?? null) {
            $options[TranslatorOptions::MAX_RETRIES] = $retries;
        }

        $deeplClient = new DeeplClient(
            $key->authenticationKey,
            $options
        );

        return $deeplClient;
    }
}
