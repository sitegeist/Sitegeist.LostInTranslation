<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

class TranslatablePropertyName
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var TranslationConnectorInterface<object>|null
     */
    protected $translationConnector;

    /**
     * @param string $name
     * @param TranslationConnectorInterface<object>|null $translationConnector
     */
    public function __construct(string $name, ?TranslationConnectorInterface $translationConnector = null)
    {
        $this->name = $name;
        $this->translationConnector = $translationConnector;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return TranslationConnectorInterface<object>|null
     */
    public function getTranslationConnector(): ?TranslationConnectorInterface
    {
        return $this->translationConnector;
    }

    public function isRepeatable(): bool
    {
        return false;
    }
}
