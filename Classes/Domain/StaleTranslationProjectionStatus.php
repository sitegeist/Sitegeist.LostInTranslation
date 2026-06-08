<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Projection\ProjectionStatusType;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\Flow\Annotations as Flow;

/**
 * The setup/catch-up status of the {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}
 * as far as the backend module needs to report it.
 *
 * The synchronization overview reads its pending counts from the stale-translation projection. If that projection has
 * never been set up (a fresh install where `./flow cr:setup` has not run yet, so its database tables are missing) those
 * reads would fault. {@see StaleTranslationProjectionStatusProvider} surfaces that condition as this value object so the
 * module can show an end-user-friendly status banner instead of crashing.
 *
 * `isReady === true` means the projection is set up AND active (caught up), so its counts can be trusted. When it is
 * false the three string fields are aimed at different readers:
 *  - `$summary` — a plain, non-technical one-liner for the editor ("Translation synchronization is not set up yet.").
 *  - `$hint` — what the reader should do, pointing at the package README rather than CLI commands.
 *  - `$details` — the technical specifics for an administrator (missing SQL, the error message); may be empty.
 */
#[Flow\Proxy(false)]
final readonly class StaleTranslationProjectionStatus
{
    public function __construct(
        public bool $isReady,
        public string $summary,
        public string $hint,
        public string $details,
    ) {
    }

    /**
     * Condense the content repository's two orthogonal status axes for the projection — setup status (is the schema
     * there?) and subscription status (has it caught up?) — into the single readiness this module renders.
     *
     * A `null` status means the projection is not registered as a subscription at all (the content repository itself was
     * never set up); the same "setup not complete" message applies as for a registered-but-unset-up projection.
     *
     * @param non-empty-string $subscriptionId the projection's subscription id, woven into the admin-facing details
     */
    public static function fromProjectionSubscriptionStatus(
        ?ProjectionSubscriptionStatus $status,
        string $subscriptionId,
    ): self {
        $readmeHint = 'The one-time setup for this feature has not been completed. '
            . 'See the "Installation" section of the Sitegeist.LostInTranslation README, or ask your administrator.';

        if ($status === null || $status->setupStatus->type === ProjectionStatusType::SETUP_REQUIRED) {
            return new self(
                isReady: false,
                summary: 'Translation synchronization is not set up yet.',
                hint: $readmeHint,
                details: $status?->setupStatus->details ?? '',
            );
        }

        if ($status->setupStatus->type === ProjectionStatusType::ERROR) {
            return new self(
                isReady: false,
                summary: 'Translation synchronization could not be set up.',
                hint: 'Please ask your administrator to complete the setup described in the Sitegeist.LostInTranslation README.',
                details: $status->setupStatus->details,
            );
        }

        $subscriptionError = $status->subscriptionError;
        $subscriptionErrorMessage = $subscriptionError !== null ? $subscriptionError->errorMessage : '';

        // Schema is in place; now the catch-up (subscription) status decides whether the counts can be trusted.
        return match ($status->subscriptionStatus) {
            SubscriptionStatus::ACTIVE => new self(
                isReady: true,
                summary: 'Translation synchronization is active.',
                hint: '',
                details: '',
            ),
            SubscriptionStatus::NEW => new self(
                isReady: false,
                summary: 'Translation synchronization is not set up yet.',
                hint: $readmeHint,
                details: '',
            ),
            SubscriptionStatus::BOOTING => new self(
                isReady: false,
                summary: 'Translation synchronization is almost ready — its setup is still finishing.',
                hint: 'Please wait a moment and reload this page. If it keeps showing, ask your administrator to complete '
                    . 'the setup described in the Sitegeist.LostInTranslation README.',
                details: 'Replay the projection to finish the catch-up: ./flow subscription:replay ' . $subscriptionId,
            ),
            SubscriptionStatus::DETACHED => new self(
                isReady: false,
                summary: 'Translation synchronization is currently unavailable.',
                hint: 'Please ask your administrator to complete the setup described in the Sitegeist.LostInTranslation README.',
                details: './flow subscription:replay ' . $subscriptionId,
            ),
            SubscriptionStatus::ERROR => new self(
                isReady: false,
                summary: 'Translation synchronization ran into a problem and is currently unavailable.',
                hint: 'Please ask your administrator to check it. The setup steps are in the Sitegeist.LostInTranslation README.',
                details: $subscriptionErrorMessage,
            ),
        };
    }
}
