<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

/**
 * @implements \IteratorAggregate<int, TranslatablePropertyName>
 */
class TranslatablePropertyNames implements \IteratorAggregate
{
    /**
     * @var TranslatablePropertyName[]
     */
    protected $translatableProperties;
    public function __construct(TranslatablePropertyName ...$translatableProperties)
    {
        $this->translatableProperties = $translatableProperties;
    }

    public function isTranslatable(string $propertyName): bool
    {
        foreach ($this->translatableProperties as $translatableProperty) {
            if ($translatableProperty->getName() == $propertyName) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param string $propertyName
     * @return TranslationConnectorInterface<object>|null
     */
    public function getTranslationObjectConnector(string $propertyName): ?TranslationConnectorInterface
    {
        foreach ($this->translatableProperties as $translatableProperty) {
            if ($translatableProperty->getName() == $propertyName) {
                return $translatableProperty->getTranslationConnector();
            }
        }
        return null;
    }

    /**
     * Check if a property is a repeatable property with translatable sub-properties
     *
     * @param string $propertyName
     * @return TranslatableRepeatablePropertyName|null
     */
    public function isTranslatableRepeatable(string $propertyName): ?TranslatableRepeatablePropertyName
    {
        foreach ($this->translatableProperties as $property) {
            if ($property->getName() === $propertyName && $property->isRepeatable()) {
                /** @var TranslatableRepeatablePropertyName $property */
                return $property;
            }
        }
        return null;
    }

    /**
     * Get all repeatable properties
     *
     * @return array<int, TranslatableRepeatablePropertyName>
     */
    public function getRepeatableProperties(): array
    {
        /** @var array<int, TranslatableRepeatablePropertyName> $result */
        $result = array_values(array_filter(
            $this->translatableProperties,
            fn($prop) => $prop->isRepeatable()
        ));
        return $result;
    }

    /**
     * @return \ArrayIterator<int, TranslatablePropertyName>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->translatableProperties);
    }
}
