<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\SynchronizationScope;

class SynchronizationScopeTest extends UnitTestCase
{
    /** @test */
    public function documentScopeMayRemoveDocumentsAndContent(): void
    {
        self::assertTrue(SynchronizationScope::Document->mayRemoveNode(true), 'Document scope must allow removing a Document');
        self::assertTrue(SynchronizationScope::Document->mayRemoveNode(false), 'Document scope must allow removing Content');
    }

    /** @test */
    public function contentScopeMayRemoveContentButNeverDocuments(): void
    {
        self::assertFalse(SynchronizationScope::Content->mayRemoveNode(true), 'Content scope must NOT remove Documents (symmetric with never auto-creating them)');
        self::assertTrue(SynchronizationScope::Content->mayRemoveNode(false), 'Content scope must remove Content');
    }
}
