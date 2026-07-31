<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Neos\ContentRepository\Core\Projection\ProjectionStateInterface;
use Neos\Flow\Annotations as Flow;

/**
 * Read model for stale translation handling
 *
 * @internal Only for consumption inside LostInTranslation.
 */
#[Flow\Proxy(false)]
final readonly class StaleTranslationReadModel implements ProjectionStateInterface
{
    public function __construct(
        public StaleTranslationFinder $staleTranslationFinder,
        public NodeTypeResolver $nodeTypeResolver,
        public StaleTranslationMaintenance $staleTranslationMaintenance,
    ) {
    }
}
