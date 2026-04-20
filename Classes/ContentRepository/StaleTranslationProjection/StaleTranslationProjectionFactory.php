<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection;

use Doctrine\DBAL\Connection;
use Neos\ContentRepository\Core\Factory\SubscriberFactoryDependencies;
use Neos\ContentRepository\Core\Projection\ProjectionFactoryInterface;

/**
 * @implements ProjectionFactoryInterface<StaleTranslationProjection>
 */
class StaleTranslationProjectionFactory implements ProjectionFactoryInterface
{
    public function __construct(
        private readonly Connection $dbal,
    ) {
    }

    public function build(
        SubscriberFactoryDependencies $projectionFactoryDependencies,
        array $options,
    ): StaleTranslationProjection {
        return new StaleTranslationProjection(
            $this->dbal,
            sprintf(
                'cr_%s_p_lostintranslation_staletranslation',
                $projectionFactoryDependencies->contentRepositoryId->value,
            ),
        );
    }
}
