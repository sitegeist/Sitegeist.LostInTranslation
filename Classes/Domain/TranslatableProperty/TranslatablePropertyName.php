<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Sitegeist\LostInTranslation\Domain\TranslationObjectConnectorInterface;

class TranslatablePropertyName
{
    /**
     * @var string
     */
    protected $name;

    /**
     * @var class-string<TranslationObjectConnectorInterface>
     */
    protected $translationObjectConnector;

    /**
     * @param string $name
     * @param class-string<TranslationObjectConnectorInterface>|null $translationObjectConnector
     */
    public function __construct(string $name, ?string $translationObjectConnector = null)
    {
        $this->name = $name;
        $this->translationObjectConnector = $translationObjectConnector;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return class-string<TranslationObjectConnectorInterface>|null
     */
    public function getTranslationObjectConnector(): ?string
    {
        return $this->translationObjectConnector;
    }
}
