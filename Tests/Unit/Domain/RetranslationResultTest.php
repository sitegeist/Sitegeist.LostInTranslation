<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\RetranslationResult;

class RetranslationResultTest extends UnitTestCase
{
    /** @test */
    public function mirroredFactorySeparatesRemovalsFromOtherTagChangesAndIsNotANoOp(): void
    {
        // A `removed`-tag command counts as a removal (that tag IS the deletion in Neos 9.1), every other tag command
        // as a tag change — so a mirrored deletion is not reported to the editor as "1 tag change".
        $result = RetranslationResult::mirrored(1, 3);

        self::assertSame(1, $result->removalCommandsDispatched);
        self::assertSame(3, $result->tagCommandsDispatched);
        self::assertSame(0, $result->stalePropertyCommandsDispatched);
        self::assertSame(0, $result->variantCommandsDispatched);
        self::assertNull($result->skippedReason);
        self::assertFalse($result->isNoOp());
    }

    /** @test */
    public function mirroredFactoryWithNothingToMirrorIsANoOp(): void
    {
        $result = RetranslationResult::mirrored(0, 0);

        self::assertTrue($result->isNoOp());
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
