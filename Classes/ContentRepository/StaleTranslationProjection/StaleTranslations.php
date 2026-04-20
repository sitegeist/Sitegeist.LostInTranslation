<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Neos\Flow\Annotations as Flow;

/**
 * Read model for a set of stale translations
 *
 * @internal Only for consumption inside LostInTranslation.
 * @implements \IteratorAggregate<int,StaleTranslation>
 */
#[Flow\Proxy(false)]
final readonly class StaleTranslations implements \IteratorAggregate, \Countable
{
    /**
     * @param list<StaleTranslation> $items
     */
    private function __construct(
        private array $items
    ) {
    }

    public static function create(StaleTranslation ...$items): self
    {
        return new self(array_values($items));
    }

    /**
     * @param list<array<string,mixed>> $databaseRows
     */
    public static function fromDatabaseRows(array $databaseRows): self
    {
        return new self(array_map(
            fn (array $databaseRow): StaleTranslation => StaleTranslation::fromDatabaseRow($databaseRow),
            $databaseRows,
        ));
    }

    public function getIterator(): \Traversable
    {
        yield from $this->items;
    }

    public function count(): int
    {
        return count($this->items);
    }
}
