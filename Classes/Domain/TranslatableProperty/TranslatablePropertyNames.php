<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

/**
 * @implements \IteratorAggregate<int, TranslatablePropertyName>
 */
class TranslatablePropertyNames implements \IteratorAggregate
{
    /**
     * @var TranslatablePropertyName[]
     */
    protected $translatableProperties;
    public function __construct(TranslatablePropertyName ... $translatableProperties)
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
     * @return \ArrayIterator<int, TranslatablePropertyName>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->translatableProperties);
    }


}
