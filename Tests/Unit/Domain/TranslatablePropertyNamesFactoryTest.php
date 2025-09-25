<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\ContentRepository\Domain\Model\NodeType;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;

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
    }

    /**
     * @dataProvider exampleProvider
     */
    public function testDetectionOfTranslatableProperties(NodeType $nodeType, TranslatablePropertyNames $expectedPropertyNames): void {
        $this->assertEquals($expectedPropertyNames, $this->translatablePropertyNamesFactory->createForNodeType($nodeType) );
    }


    public function testPropertiesWithConfiguredConnector(): void
    {
        $mockTranslationConnector = $this->createMock(TranslationConnectorInterface::class);

        $mockObjectManager = $this->createMock(ObjectManagerInterface::class);
        $mockObjectManager
            ->expects(self::once())
            ->method('get')
            ->with('Example\TranslationConnector')
            ->willReturn($mockTranslationConnector);

        $this->inject($this->translatablePropertyNamesFactory, 'objectManager', $mockObjectManager);
        $this->inject($this->translatablePropertyNamesFactory, 'translateTypesWithConnectors', true);
        $this->inject($this->translatablePropertyNamesFactory, 'translationConnectors', ['Example\Class' => 'Example\TranslationConnector']);

        $nodeType = new NodeType('Example', [], [
            'properties' => [
                'object' => [
                    'type' => 'Example\Class',
                ],
                'objectWithoutConnector' => [
                    'type' => 'Example\Other\Class',
                ],
                'objectWithConnectorButDisabled' => [
                    'type' => 'Example\Class',
                    'options' => [
                        'automaticTranslation' => false,
                    ]
                ],
            ]
        ]);

        $expectedPropertyNames = new TranslatablePropertyNames(
        new TranslatablePropertyName('object', $mockTranslationConnector)
        );

        $this->assertEquals($expectedPropertyNames, $this->translatablePropertyNamesFactory->createForNodeType($nodeType) );
    }

    public function testPropertiesWithConfiguredConnectorOptIn(): void
    {
        $mockTranslationConnector = $this->createMock(TranslationConnectorInterface::class);

        $mockObjectManager = $this->createMock(ObjectManagerInterface::class);
        $mockObjectManager
            ->expects(self::once())
            ->method('get')
            ->with('Example\TranslationConnector')
            ->willReturn($mockTranslationConnector);

        $this->inject($this->translatablePropertyNamesFactory, 'objectManager', $mockObjectManager);
        $this->inject($this->translatablePropertyNamesFactory, 'translateTypesWithConnectors', false);
        $this->inject($this->translatablePropertyNamesFactory, 'translationConnectors', ['Example\Class' => 'Example\TranslationConnector']);

        $nodeType = new NodeType('Example', [], [
            'properties' => [
                'object' => [
                    'type' => 'Example\Class',
                    'options' => [
                        'automaticTranslation' => true,
                    ]
                ],
                'objectWithoutConnector' => [
                    'type' => 'Example\Other\Class',
                ],
                'objectWithoutOptIn' => [
                    'type' => 'Example\Class',
                ],
            ]
        ]);

        $expectedPropertyNames = new TranslatablePropertyNames(
            new TranslatablePropertyName('object', $mockTranslationConnector)
        );

        $this->assertEquals($expectedPropertyNames, $this->translatablePropertyNamesFactory->createForNodeType($nodeType) );
    }

}
