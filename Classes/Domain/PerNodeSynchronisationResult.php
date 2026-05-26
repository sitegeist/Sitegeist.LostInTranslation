<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final readonly class PerNodeSynchronisationResult
{
    public function __construct(
        public NodeAggregateId $nodeAggregateId,
        public RetranslationResult $result,
    ) {
    }
}
