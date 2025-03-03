<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use DeepL\DeepLClient;
use DeepL\TranslatorOptions;
use Psr\Http\Client\ClientInterface;

class DeeplClientFactory
{
    protected array $settings;

    public function __construct(
        private readonly ClientInterface $httpClient,
        private readonly DeepLAuthenticationKeyFactory $authenticationKeyFactory
    ) {
    }

    public function injectSettings(array $settings): void
    {
         $this->settings = $settings['DeepLApi'];
    }

    public function create(): DeeplClient
    {
        $key = $this->authenticationKeyFactory->create();

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
