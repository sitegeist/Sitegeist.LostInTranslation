<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\PerNodeSynchronizationResult;
use Sitegeist\LostInTranslation\Domain\RetranslationResult;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizationResult;

class WorkspaceSynchronizationResultTest extends UnitTestCase
{
    private function result(string $id, RetranslationResult $retranslationResult): PerNodeSynchronizationResult
    {
        return new PerNodeSynchronizationResult(NodeAggregateId::fromString($id), $retranslationResult);
    }

    /** @test */
    public function totalsAggregateEveryResultKindAcrossNodes(): void
    {
        $result = new WorkspaceSynchronizationResult([
            $this->result('node-translated', new RetranslationResult(stalePropertyCommandsDispatched: 2, variantCommandsDispatched: 1)),
            $this->result('node-removed', RetranslationResult::mirrored(1, 0)),
            $this->result('node-tagged', RetranslationResult::mirrored(0, 3)),
            $this->result('node-dry-run', RetranslationResult::skipped('dry-run')),
            $this->result('node-needs-full', RetranslationResult::skippedRequiringFullSync('ancestor missing')),
        ]);

        self::assertSame(2, $result->totalStalePropertyCommandsDispatched());
        self::assertSame(1, $result->totalVariantCommandsDispatched());
        self::assertSame(1, $result->totalRemovalCommandsDispatched());
        self::assertSame(3, $result->totalTagCommandsDispatched());
        // Both skip flavors carry a skippedReason and therefore count as skipped.
        self::assertSame(2, $result->totalSkippedNodes());
        self::assertSame(1, $result->totalNodesRequiringFullSync());
    }

    /** @test */
    public function anEmptyResultReportsZeroForEveryTotal(): void
    {
        $result = new WorkspaceSynchronizationResult([]);

        self::assertSame(0, $result->totalStalePropertyCommandsDispatched());
        self::assertSame(0, $result->totalVariantCommandsDispatched());
        self::assertSame(0, $result->totalRemovalCommandsDispatched());
        self::assertSame(0, $result->totalTagCommandsDispatched());
        self::assertSame(0, $result->totalSkippedNodes());
        self::assertSame(0, $result->totalNodesRequiringFullSync());
    }

    /** @test */
    public function aSkippedRunCarriesItsReason(): void
    {
        $result = WorkspaceSynchronizationResult::skipped('target workspace missing');

        self::assertSame('target workspace missing', $result->skippedReason);
        self::assertSame([], $result->perNodeResults);
    }
}
