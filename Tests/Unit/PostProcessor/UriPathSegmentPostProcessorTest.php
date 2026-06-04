<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\PostProcessor;

use Neos\Flow\Tests\UnitTestCase;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Sitegeist\LostInTranslation\Domain\PostProcessor\UriPathSegmentPostProcessor;

class UriPathSegmentPostProcessorTest extends UnitTestCase
{
    protected UriPathSegmentPostProcessor $postProcessor;

    protected NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator;

    public function setUp(): void
    {
        $this->postProcessor = new UriPathSegmentPostProcessor();
        $this->nodeUriPathSegmentGenerator = $this->createMock(NodeUriPathSegmentGenerator::class);
        $this->inject($this->postProcessor, 'nodeUriPathSegmentGenerator', $this->nodeUriPathSegmentGenerator);
    }

    /** @test */
    public function aValidSlugPassesThroughUnchanged(): void
    {
        $this->nodeUriPathSegmentGenerator->expects(self::never())->method('generateUriPathSegment');

        self::assertSame(
            'my-test-uri-path-translated',
            $this->postProcessor->process('my-test-uri-path-translated'),
        );
    }

    /** @test */
    public function anInvalidSlugIsRegenerated(): void
    {
        $this->nodeUriPathSegmentGenerator
            ->expects(self::once())
            ->method('generateUriPathSegment')
            ->with(null, 'my-test-uri-path translated')
            ->willReturn('my-test-uri-path-translated');

        self::assertSame(
            'my-test-uri-path-translated',
            $this->postProcessor->process('my-test-uri-path translated'),
        );
    }
}
