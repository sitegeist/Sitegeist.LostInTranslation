<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

/**
 * Immutable collection of {@see SynchronisationRule}s loaded from settings.
 *
 * @implements \IteratorAggregate<int,SynchronisationRule>
 */
#[Flow\Proxy(false)]
final readonly class SynchronisationRules implements \IteratorAggregate, \Countable
{
    /**
     * @var list<SynchronisationRule>
     */
    public array $items;

    public function __construct(SynchronisationRule ...$items)
    {
        $this->items = array_values($items);
    }

    /**
     * @param array<int,array<string,string>> $rawRules Settings array as read from Flow configuration.
     */
    public static function fromArray(array $rawRules): self
    {
        return new self(...array_map(
            static fn (array $row): SynchronisationRule => SynchronisationRule::fromArray($row),
            $rawRules,
        ));
    }

    /**
     * Rules whose `sourceWorkspaceName` matches the workspace that just received a publish
     * (i.e. the publish target / base workspace).
     */
    public function forPublicationTarget(WorkspaceName $publicationTarget): self
    {
        return new self(...array_filter(
            $this->items,
            static fn (SynchronisationRule $rule): bool => $rule->sourceWorkspaceName === $publicationTarget->value,
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

    public function isEmpty(): bool
    {
        return $this->items === [];
    }
}
