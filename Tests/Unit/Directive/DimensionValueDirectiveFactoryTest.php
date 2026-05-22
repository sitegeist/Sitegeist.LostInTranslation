<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Directive;

use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\Dimension\ContentDimensionConstraintSet;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValue;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValues;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValueSpecializationDepth;
use Neos\ContentRepository\Core\Dimension\ContentDimensionValueVariationEdges;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePointSet;
use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirective;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Symfony\Component\Yaml\Yaml;

class DimensionValueDirectiveFactoryTest extends UnitTestCase
{
    protected DimensionValueDirectiveFactory $subject;

    public function setUp(): void
    {
        $this->subject = new DimensionValueDirectiveFactory();
    }

    /** @test */
    public function createForContentDiomension(): void
    {
        $dimension = new ContentDimension(
            new ContentDimensionId('language'),
            new ContentDimensionValues(
                [
                    new ContentDimensionValue(
                        'de',
                        new ContentDimensionValueSpecializationDepth(0),
                        new ContentDimensionConstraintSet([]),
                    ),
                    new ContentDimensionValue(
                        'de_bavaria',
                        new ContentDimensionValueSpecializationDepth(0),
                        new ContentDimensionConstraintSet([]),
                        ['options' => ['deeplLanguage' => false]]
                    ),
                    new ContentDimensionValue(
                        'dk',
                        new ContentDimensionValueSpecializationDepth(0),
                        new ContentDimensionConstraintSet([]),
                        ['options' => ['deeplLanguage' => 'DA']]
                    ),
                    new ContentDimensionValue(
                        'en_uk',
                        new ContentDimensionValueSpecializationDepth(0),
                        new ContentDimensionConstraintSet([]),
                        ['options' => ['deeplLanguage' => 'EN:EN-GB']]
                    ),
                ],
            ),
            new ContentDimensionValueVariationEdges()
        );

        $this->assertEquals(
            new DimensionValueDirective('DE', 'DE'),
            $this->subject->tryCreateForDimensionAndOriginDimensionSpacePoint($dimension, OriginDimensionSpacePoint::fromArray(['language' => 'de']))
        );

        $this->assertEquals(
            new DimensionValueDirective(null, null),
            $this->subject->tryCreateForDimensionAndOriginDimensionSpacePoint($dimension, OriginDimensionSpacePoint::fromArray(['language' => 'de_bavaria']))
        );

        $this->assertEquals(
            null,
            $this->subject->tryCreateForDimensionAndOriginDimensionSpacePoint($dimension, OriginDimensionSpacePoint::fromArray(['language' => 'de_platt']))
        );

        $this->assertEquals(
            new DimensionValueDirective('DA', 'DA'),
            $this->subject->tryCreateForDimensionAndOriginDimensionSpacePoint($dimension, OriginDimensionSpacePoint::fromArray(['language' => 'dk']))
        );

        $this->assertEquals(
            new DimensionValueDirective('EN', 'EN-GB'),
            $this->subject->tryCreateForDimensionAndOriginDimensionSpacePoint($dimension, OriginDimensionSpacePoint::fromArray(['language' => 'en_uk']))
        );
    }
}
