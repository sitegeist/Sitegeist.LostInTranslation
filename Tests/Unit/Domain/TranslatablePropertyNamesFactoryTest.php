<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;

class TranslatablePropertyNamesFactoryTest extends UnitTestCase
{

    private TranslatablePropertyNamesFactory $translatablePropertyNamesFactory;
    public function setUp(): void
    {
        $this->translatablePropertyNamesFactory = new TranslatablePropertyNamesFactory();
        $this->inject($this->translatablePropertyNamesFactory, 'translateInlineEditables', true);
    }

    public function exampleProvider(): \Generator
    {
        yield 'empty' => [
            new NodeType('Example', [], []),
            new TranslatablePropertyNames(),
        ];

        yield 'ignored' => [
            new NodeType('Example', [], [
                'properties' => [
                    'stringProperty' => [
                        'type' => 'string',
                    ]
                ]
            ]),
            new TranslatablePropertyNames(),
        ];

        yield 'inline editable' => [
            new NodeType('Example', [], [
                'properties' => [
                    'inlineEditableTextProperty' => [
                        'type' => 'string',
                        'ui' => [
                            'inlineEditable' => true,
                        ]
                    ]
                ]
            ]),
            new TranslatablePropertyNames(
                new TranslatablePropertyName('inlineEditableTextProperty'),
            ),
        ];

        yield 'automaticTranslation' => [
            new NodeType('Example', [], [
                'properties' => [
                    'textPropertyWithOptions' => [
                        'type' => 'string',
                        'options' => [
                            'automaticTranslation' => true,
                        ]
                    ]
                ]
            ]),
            new TranslatablePropertyNames(
                new TranslatablePropertyName('textPropertyWithOptions'),
            ),
        ];

        yield 'value object property' => [
            new NodeType('Example', [], [
                'properties' => [
                    'valueObjectProperty' => [
                        'type' => 'Some\Class',
                        'options' => [
                            'automaticTranslationConnector' => 'Some\Class\Name',
                            'automaticTranslation' => true
                        ]
                    ]
                ]
            ]),
            new TranslatablePropertyNames(
                new TranslatablePropertyName('valueObjectProperty', 'Some\Class\Name'),
            ),
        ];

        yield 'test image property' => [
            new NodeType('Image', [], [
                'properties' => [
                    'image' => [
                        'type' => 'Sitegeist\Kaleidoscope\ValueObjects\ImageSourceProxy',
                        'options' => [
                            'automaticTranslationConnector' => 'Sitegeist\Kaleidoscope\ValueObjects\Connector\ImageSourceProxyLostInTranslationConnector',
                            'automaticTranslation' => true
                        ]
                    ]
                ]
            ]),
            new TranslatablePropertyNames(
                new TranslatablePropertyName('image', 'Sitegeist\Kaleidoscope\ValueObjects\Connector\ImageSourceProxyLostInTranslationConnector'),
            ),
        ];
    }

    /**
     * @dataProvider exampleProvider
     */
    public function testDetectionOfTranslatableProperties(NodeType $nodeType, TranslatablePropertyNames $expectedPropertyNames): void {
        $this->assertEquals($expectedPropertyNames, $this->translatablePropertyNamesFactory->createForNodeType($nodeType) );
    }
}
