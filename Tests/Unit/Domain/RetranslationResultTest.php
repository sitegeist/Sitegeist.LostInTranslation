<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\RetranslationResult;

class RetranslationResultTest extends UnitTestCase
{
    /** @test */
    public function removedFactoryReportsOneRemovalAndIsNotANoOp(): void
    {
        $result = RetranslationResult::removed();

        self::assertSame(1, $result->removalCommandsDispatched);
        self::assertSame(0, $result->stalePropertyCommandsDispatched);
        self::assertSame(0, $result->variantCommandsDispatched);
        self::assertSame(0, $result->tagCommandsDispatched);
        self::assertNull($result->skippedReason);
        self::assertFalse($result->isNoOp());
    }

    /** @test */
    public function taggedFactoryReportsTheGivenTagCountAndIsNotANoOp(): void
    {
        $result = RetranslationResult::tagged(3);

        self::assertSame(3, $result->tagCommandsDispatched);
        self::assertSame(0, $result->removalCommandsDispatched);
        self::assertNull($result->skippedReason);
        self::assertFalse($result->isNoOp());
    }

    /** @test */
    public function skippedFactoryCarriesTheReasonAndIsANoOp(): void
    {
        $result = RetranslationResult::skipped('dry-run');

        self::assertSame('dry-run', $result->skippedReason);
        self::assertFalse($result->requiresFullSync);
        self::assertTrue($result->isNoOp());
    }

    /** @test */
    public function skippedRequiringFullSyncFlagsTheFullSyncHint(): void
    {
        $result = RetranslationResult::skippedRequiringFullSync('ancestor missing');

        self::assertSame('ancestor missing', $result->skippedReason);
        self::assertTrue($result->requiresFullSync);
        self::assertTrue($result->isNoOp());
    }

    /** @test */
    public function aResultWithNoDispatchedCommandsIsANoOp(): void
    {
        self::assertTrue((new RetranslationResult(0, 0))->isNoOp());
        self::assertFalse((new RetranslationResult(1, 0))->isNoOp(), 'a stale property update is not a no-op');
        self::assertFalse((new RetranslationResult(0, 1))->isNoOp(), 'a variant creation is not a no-op');
    }
}
