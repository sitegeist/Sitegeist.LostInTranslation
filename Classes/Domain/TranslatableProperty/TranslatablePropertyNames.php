<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

/**
 * @implements \IteratorAggregate<string, TranslatablePropertyName>
 */
class TranslatablePropertyNames implements \IteratorAggregate
{
    /**
     * @var array<string, TranslatablePropertyName>
     */
    protected $translatableProperties;

    public function __construct(TranslatablePropertyName ...$translatableProperties)
    {
        $this->translatableProperties = [];
        foreach ($translatableProperties as $translatableProperty) {
            $this->translatableProperties[$translatableProperty->getName()] = $translatableProperty;
        }
    }

    public function isTranslatable(string $propertyName): bool
    {
        if (array_key_exists($propertyName, $this->translatableProperties)) {
            return true;
        }
        return false;
    }

    public function getByPropertyName(string $propertyName): ?TranslatablePropertyName
    {
        if (array_key_exists($propertyName, $this->translatableProperties)) {
            return $this->translatableProperties[$propertyName];
        }
        return null;
    }

    /**
     * @param string $propertyName
     * @return TranslationConnectorInterface<object>|null
     */
    public function getTranslationObjectConnector(string $propertyName): ?TranslationConnectorInterface
    {
        if (array_key_exists($propertyName, $this->translatableProperties)) {
            return $this->translatableProperties[$propertyName]->getTranslationConnector();
        }
        return null;
    }

    public function hasStringTranslationMode(string $propertyName, StringTranslationMode $stringTranslationMode): bool
    {
        if (array_key_exists($propertyName, $this->translatableProperties)) {
            return $this->translatableProperties[$propertyName]->getStringTranslationMode() === $stringTranslationMode;
        }
        return false;
    }

    /**
     * @return \ArrayIterator<string, TranslatablePropertyName>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->translatableProperties);
    }
}
