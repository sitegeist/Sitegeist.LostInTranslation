<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Annotations as Flow;

/**
 * What a {@see Retranslator} subtree walk did, or — for {@see Retranslator::planSubtree()} — would do.
 *
 * Carries node ids rather than the counts of {@see RetranslationResult} because a workspace-level caller has to
 * deduplicate across subtrees: a real run gets that for free (every dispatched command clears the stale row it
 * satisfies, so a later walk over the same node comes back a no-op), while a dry run dispatches nothing and would
 * otherwise count a nested stale node once for its own record and again for every record above it.
 */
#[Flow\Proxy(false)]
final readonly class RetranslationPlan
{
    public function __construct(
        /**
         * Nodes whose existing target variant gets (or would get) a translated `SetNodeProperties` for the properties
         * the projection flagged stale.
         *
         * @var list<NodeAggregateId>
         */
        public array $propertyUpdates,
        /**
         * Nodes with no target variant yet, for which a `CreateNodeVariant` is (or would be) dispatched. Tethered
         * nodes never appear here — they materialise with their ancestor's variant.
         *
         * @var list<NodeAggregateId>
         */
        public array $variantCreations,
        /**
         * Set when the walk short-circuited before doing anything — see {@see RetranslationResult::$skippedReason}.
         */
        public ?string $skippedReason = null,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self([], [], $reason);
    }
}
