<?php

namespace Sitegeist\LostInTranslation\Domain\Retranslation;

use Neos\ContentRepository\Domain\Model\NodeInterface;

readonly class Discrepancy
{
    public function __construct(
        public NodeInterface $sourceNode,
        public ?NodeInterface $targetNode,
        public \DateTimeInterface $discrepancyDate,
        public ?string $description
    )
    {
    }
}
