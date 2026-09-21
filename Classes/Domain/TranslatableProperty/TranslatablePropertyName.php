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
     * @var bool
     */
    protected $isPlainText;

    /**
     * @param string $name
     * @param TranslationConnectorInterface<object>|null $translationConnector
     * @param bool $isPlainText
     */
    public function __construct(string $name, ?TranslationConnectorInterface $translationConnector = null, bool $isPlainText = false)
    {
        $this->name = $name;
        $this->translationConnector = $translationConnector;
        $this->isPlainText = $isPlainText;
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

    public function isPlainText(): bool
    {
        return $this->isPlainText;
    }
}
