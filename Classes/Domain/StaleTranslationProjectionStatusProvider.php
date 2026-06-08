<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Service\ContentRepositoryMaintainerFactory;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;

/**
 * Reports whether the stale-translation projection is ready to be queried, so the backend module can show an actionable
 * status instead of faulting on missing tables when `./flow cr:setup` has not been run yet.
 *
 * The projection is registered as a content-repository subscription under the configured projection name (see
 * `Settings.Neos.yaml`), which doubles as its {@see SubscriptionId}. This provider reads that subscription's status from
 * the {@see \Neos\ContentRepository\Core\Service\ContentRepositoryMaintainer} and hands it to
 * {@see StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus()} for the actual interpretation.
 */
#[Flow\Scope('singleton')]
class StaleTranslationProjectionStatusProvider
{
    /**
     * The projection's name in `Settings.Neos.yaml`, which the registry uses verbatim as its subscription id.
     */
    private const SUBSCRIPTION_ID = 'Sitegeist.LostInTranslation:StaleTranslations';

    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    public function forContentRepository(ContentRepositoryId $contentRepositoryId): StaleTranslationProjectionStatus
    {
        $maintainer = $this->contentRepositoryRegistry->buildService(
            $contentRepositoryId,
            new ContentRepositoryMaintainerFactory(),
        );
        $subscriptionId = SubscriptionId::fromString(self::SUBSCRIPTION_ID);

        $subscriptionStatus = null;
        foreach ($maintainer->status()->subscriptionStatus as $status) {
            if ($status instanceof ProjectionSubscriptionStatus && $status->subscriptionId->equals($subscriptionId)) {
                $subscriptionStatus = $status;
                break;
            }
        }

        return StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $subscriptionStatus,
            self::SUBSCRIPTION_ID,
        );
    }
}
