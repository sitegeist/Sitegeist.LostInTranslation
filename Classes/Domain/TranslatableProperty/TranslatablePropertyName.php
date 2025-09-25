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
     * @var class-string<TranslationConnectorInterface<object>>|null
     */
    protected $translationConnectorClassName;

    /**
     * @param string $name
     * @param class-string<TranslationConnectorInterface<object>>|null $translationConnectorClassName
     */
    public function __construct(string $name, ?string $translationConnectorClassName = null)
    {
        $this->name = $name;
        $this->translationConnectorClassName = $translationConnectorClassName;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return class-string<TranslationConnectorInterface<object>>|null
     */
    public function getTranslationConnectorClassName(): ?string
    {
        return $this->translationConnectorClassName;
    }
}
