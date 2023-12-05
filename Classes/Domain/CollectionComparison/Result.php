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
     * @var NodeReference[]
     */
    public array $missing;

    /**
     * @var NodeReference[]
     */
    public array $outdated;

    private function __construct(array $missing, array $outdated) {
        $this->missing = $missing;
        $this->outdated = $outdated;
    }

    public static function createEmpty(): static
    {
        return new static([], []);
    }

    public function withMissingNodes(NodeReference ...$missingNodes): static
    {
        return new static($missingNodes, $this->outdated);
    }

    public function withOutdatedNodes(NodeReference ...$outdatedNodes): static
    {
        return new static($this->missing, $outdatedNodes);
    }

    public function getHasDifferences(): bool
    {
        return count($this->missing) > 0 || count($this->outdated) > 0;
    }

    /**
     * @return NodeReference[]
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * @return NodeReference[]
     */
    public function getOutdated(): array
    {
        return $this->outdated;
    }
}
