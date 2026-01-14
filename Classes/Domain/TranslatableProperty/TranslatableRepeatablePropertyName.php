<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

/**
 * Represents a repeatable property that contains translatable sub-properties
 */
class TranslatableRepeatablePropertyName extends TranslatablePropertyName
{
    /**
     * @var array<string>
     */
    protected array $translatableSubProperties;

    public function __construct(string $name, array $translatableSubProperties)
    {
        parent::__construct($name);
        $this->translatableSubProperties = $translatableSubProperties;
    }

    /**
     * @return array<string>
     */
    public function getTranslatableSubProperties(): array
    {
        return $this->translatableSubProperties;
    }

    public function isRepeatable(): bool
    {
        return true;
    }
}
