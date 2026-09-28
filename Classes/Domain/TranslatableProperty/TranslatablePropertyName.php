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

    protected ?StringTranslationMode $stringTranslationMode;

    /**
     * @param string $name
     * @param TranslationConnectorInterface<object>|null $translationConnector
     */
    public function __construct(
        string $name,
        ?TranslationConnectorInterface $translationConnector = null,
        ?StringTranslationMode $stringTranslationMode = null
    ) {
        $this->name = $name;
        $this->translationConnector = $translationConnector;
        $this->stringTranslationMode = $stringTranslationMode;
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

    public function getStringTranslationMode(): ?StringTranslationMode
    {
        return $this->stringTranslationMode;
    }
}
