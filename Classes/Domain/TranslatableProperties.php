<?php
declare(strict_types=1);
namespace Sitegeist\LostInTranslation\Domain;

/**
 * @implements \IteratorAggregate<int, TranslatableProperty>
 */
class TranslatableProperties implements \IteratorAggregate
{
    /**
     * @var TranslatableProperty[]
     */
    protected $translatableProperties;
    public function __construct(TranslatableProperty ... $translatableProperties)
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
     * @return \ArrayIterator<int, TranslatableProperty>
     */
    public function getIterator(): \Iterator
    {
        return new \ArrayIterator($this->translatableProperties);
    }


}
