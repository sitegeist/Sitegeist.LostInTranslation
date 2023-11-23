<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Comparison;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class Result
{
    /**
     * @var NodeInformation[]
     */
    public array $missing;

    /**
     * @var NodeInformation[]
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

    public function withMissingNodes(NodeInformation ...$missingNodes): static
    {
        return new static($missingNodes, $this->outdated);
    }

    public function withOutdatedNodes(NodeInformation ...$outdatedNodes): static
    {
        return new static($this->missing, $outdatedNodes);
    }

    public function getHasDifferences(): bool
    {
        return count($this->missing) > 0 || count($this->outdated) > 0;
    }

    /**
     * @return NodeInformation[]
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    /**
     * @return NodeInformation[]
     */
    public function getOutdated(): array
    {
        return $this->outdated;
    }
}
