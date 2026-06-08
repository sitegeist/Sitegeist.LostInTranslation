<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\ContentRepository\Core\Projection\ProjectionStatus;
use Neos\ContentRepository\Core\Subscription\ProjectionSubscriptionStatus;
use Neos\ContentRepository\Core\Subscription\SubscriptionError;
use Neos\ContentRepository\Core\Subscription\SubscriptionId;
use Neos\ContentRepository\Core\Subscription\SubscriptionStatus;
use Neos\EventStore\Model\Event\SequenceNumber;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\StaleTranslationProjectionStatus;

class StaleTranslationProjectionStatusTest extends UnitTestCase
{
    private const SUBSCRIPTION_ID = 'Sitegeist.LostInTranslation:StaleTranslations';

    private function subscriptionStatus(
        SubscriptionStatus $subscriptionStatus,
        ProjectionStatus $setupStatus,
        ?SubscriptionError $subscriptionError = null,
    ): ProjectionSubscriptionStatus {
        return ProjectionSubscriptionStatus::create(
            subscriptionId: SubscriptionId::fromString(self::SUBSCRIPTION_ID),
            subscriptionStatus: $subscriptionStatus,
            subscriptionPosition: SequenceNumber::fromInteger(0),
            subscriptionError: $subscriptionError,
            setupStatus: $setupStatus,
        );
    }

    /** @test */
    public function aMissingSubscriptionIsReportedAsNotSetUp(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(null, self::SUBSCRIPTION_ID);

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization is not set up yet.', $status->summary);
        self::assertStringContainsString('README', $status->hint);
    }

    /** @test */
    public function aSetupRequiredSchemaIsReportedAsNotSetUpRegardlessOfSubscriptionStatus(): void
    {
        // Tables missing while the subscription row still claims ACTIVE — the exact state of a dropped/never-created
        // schema, where the setup status must win over the (stale) subscription status.
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(
                SubscriptionStatus::ACTIVE,
                ProjectionStatus::setupRequired('CREATE TABLE …'),
            ),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization is not set up yet.', $status->summary);
        self::assertStringContainsString('README', $status->hint);
        self::assertSame('CREATE TABLE …', $status->details);
    }

    /** @test */
    public function aSetupErrorIsReported(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(
                SubscriptionStatus::ACTIVE,
                ProjectionStatus::error('Failed to connect to database'),
            ),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization could not be set up.', $status->summary);
        self::assertSame('Failed to connect to database', $status->details);
    }

    /** @test */
    public function anActiveProjectionWithOkSchemaIsReady(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(SubscriptionStatus::ACTIVE, ProjectionStatus::ok()),
            self::SUBSCRIPTION_ID,
        );

        self::assertTrue($status->isReady);
        self::assertSame('', $status->hint);
    }

    /** @test */
    public function aNewSubscriptionWithOkSchemaIsReportedAsNotSetUp(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(SubscriptionStatus::NEW, ProjectionStatus::ok()),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization is not set up yet.', $status->summary);
        self::assertStringContainsString('README', $status->hint);
    }

    /** @test */
    public function aBootingSubscriptionIsReportedAsCatchingUp(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(SubscriptionStatus::BOOTING, ProjectionStatus::ok()),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization is almost ready — its setup is still finishing.', $status->summary);
        self::assertStringContainsString('README', $status->hint);
        self::assertStringContainsString('./flow subscription:replay ' . self::SUBSCRIPTION_ID, $status->details);
    }

    /** @test */
    public function aDetachedSubscriptionIsReportedAsDetached(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(SubscriptionStatus::DETACHED, ProjectionStatus::ok()),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization is currently unavailable.', $status->summary);
        self::assertStringContainsString('README', $status->hint);
        self::assertStringContainsString('./flow subscription:replay ' . self::SUBSCRIPTION_ID, $status->details);
    }

    /** @test */
    public function anErroredSubscriptionSurfacesTheErrorMessage(): void
    {
        $status = StaleTranslationProjectionStatus::fromProjectionSubscriptionStatus(
            $this->subscriptionStatus(
                SubscriptionStatus::ERROR,
                ProjectionStatus::ok(),
                SubscriptionError::fromPreviousStatusAndException(
                    SubscriptionStatus::ACTIVE,
                    new \RuntimeException('apply() blew up'),
                ),
            ),
            self::SUBSCRIPTION_ID,
        );

        self::assertFalse($status->isReady);
        self::assertSame('Translation synchronization ran into a problem and is currently unavailable.', $status->summary);
        self::assertStringContainsString('apply() blew up', $status->details);
    }
}
