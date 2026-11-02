<?php

namespace Sitegeist\LostInTranslation\Domain\Retranslation;

use Neos\ContentRepository\Domain\Model\NodeInterface;

readonly class InterdimensionalModificationComparisonResult
{
    public DiscrepancyCollection $discrepancies;

    public function __construct(
        public NodeInterface $sourceNode,
        public ?NodeInterface $targetNode,
    )
    {
        $this->discrepancies = new DiscrepancyCollection();
    }

    public function addSubResult(InterdimensionalModificationComparisonResult $result):  void
    {
        $this->discrepancies->addMultipleDiscrepancies(...$result->discrepancies->items);
    }

    public function addOneDiscrepancy (Discrepancy $discrepancy):  void
    {
        $this->discrepancies->addOneDiscrepancy($discrepancy);
    }

    public function hasDiscrepancies(): bool
    {
        return count($this->discrepancies->items) > 0;
    }

    public function getEarliestDiscrepancyDate(): ?\DateTimeInterface
    {
        return $this->discrepancies->getEarliestDiscrepancy()?->discrepancyDate;
    }
}
