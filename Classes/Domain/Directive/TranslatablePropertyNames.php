<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;

/**
 * @implements \IteratorAggregate<string, TranslatablePropertyName>
 */
readonly class TranslatablePropertyNames implements \Countable, \IteratorAggregate
{
    /**
     * @var array<string, TranslatablePropertyName>
     */
    public array $items;

    public function __construct(
        TranslatablePropertyName ...$items,
    ) {
        $itemsByName = [];
        foreach ($items as $item) {
            $itemsByName[$item->propertyName->value] = $item;
        }
        $this->items = $itemsByName;
    }

    public function findByName(PropertyName|string $name): ?TranslatablePropertyName
    {
        if ($name instanceof PropertyName) {
            $name = $name->value;
        }
        return $this->items[$name] ?? null;
    }

    /**
     * @return \Traversable<string, TranslatablePropertyName>
     */
    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }

    public static function createEmpty(): self
    {
        return new self();
    }

    /**
     * @param TranslatablePropertyName[] $items
     */
    public static function fromArray(array $items): self
    {
        return new self(...$items);
    }
}
