<?php

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Neos\Flow\Annotations as Flow;

/**
 * @implements \IteratorAggregate<int,StaleTranslation>
 */
#[Flow\Proxy(false)]
final readonly class StaleTranslations implements \IteratorAggregate
{
    /**
     * @var array<int,StaleTranslation>
     */
    public array $items;

    public function __construct(StaleTranslation ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * @param array<array<string,mixed>> $databaseRows
     */
    public static function fromDatabaseRows(array $databaseRows): self
    {
        return new self(...array_map(
            fn (array $databaseRow): StaleTranslation => StaleTranslation::fromDatabaseRow($databaseRow),
            $databaseRows,
        ));
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }
}
