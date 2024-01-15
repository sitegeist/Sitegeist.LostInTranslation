<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\CollectionComparison;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class Result
{
    /**
     * @var MissingNodeReference[]
     */
    public array $missing;

    /**
     * @var OutdatedNodeReference[]
     */
    public array $outdated;

    /**
     * @param array<int,MissingNodeReference> $missing
     * @param array<int,OutdatedNodeReference> $outdated
     */
    private function __construct(array $missing, array $outdated)
    {
        $this->missing = $missing;
        $this->outdated = $outdated;
    }

    public static function createEmpty(): static
    {
        return new static([], []);
    }

    public function withMissingNodes(MissingNodeReference ...$missingNodes): static
    {
        return new static([...$this->missing, ...$missingNodes], $this->outdated);
    }

    public function withOutdatedNodes(OutdatedNodeReference ...$outdatedNodes): static
    {
        return new static($this->missing, [...$this->outdated, ...$outdatedNodes]);
    }

    public function getHasDifferences(): bool
    {
        return count($this->missing) > 0 || count($this->outdated) > 0;
    }

    /**
     * @return MissingNodeReference[]
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * @return OutdatedNodeReference[]
     */
    public function getOutdated(): array
    {
        return $this->outdated;
    }
}
