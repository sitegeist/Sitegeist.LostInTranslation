<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationArrayConnectorInterface;

class TranslatablePropertyName
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var TranslationConnectorInterface<object>|TranslationArrayConnectorInterface<object>|null
     */
    protected $translationConnector;

    /**
     * @param string $name
     * @param TranslationConnectorInterface<object>|TranslationArrayConnectorInterface<object>|null $translationConnector
     */
    public function __construct(string $name, TranslationConnectorInterface | TranslationArrayConnectorInterface | null $translationConnector = null)
    {
        $this->name = $name;
        $this->translationConnector = $translationConnector;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return TranslationConnectorInterface<object>|TranslationArrayConnectorInterface<object>|null
     */
    public function getTranslationConnector(): TranslationConnectorInterface | TranslationArrayConnectorInterface | null
    {
        return $this->translationConnector;
    }
}
