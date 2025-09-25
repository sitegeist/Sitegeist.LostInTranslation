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
     * @return class-string<TranslationConnectorInterface<object>>|null
     */
    public function getTranslationObjectConnector(string $propertyName): ?string
    {
        foreach ($this->translatableProperties as $translatableProperty) {
            if ($translatableProperty->getName() == $propertyName) {
                return $translatableProperty->getTranslationConnectorClassName();
            }
        }
        return null;
    }

    /**
     * @return \ArrayIterator<int, TranslatablePropertyName>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->translatableProperties);
    }
}
