<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Directive;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\Flow\ObjectManagement\ObjectManagerInterface;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirective;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyName;
use Sitegeist\LostInTranslation\Domain\Directive\TranslatablePropertyNames;
use Sitegeist\LostInTranslation\Domain\PostProcessor\TranslatedPropertyPostProcessorInterface;
use Sitegeist\LostInTranslation\Domain\TranslationConnectorInterface;
use Symfony\Component\Yaml\Yaml;

class NodeTypeTranslationDirectiveFactoryTest extends UnitTestCase
{
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    public function setUp(): void
    {
        $this->nodeTypeTranslationDirectiveFactory = new NodeTypeTranslationDirectiveFactory();
        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translateInlineEditables', true);
    }

    /** @test */
    public function automaticTranslationCanBeDisabled(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse(<<<EOL
                options:
                    automaticTranslation: false
                EOL
            )
        );

        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $this->assertEquals(false, $directive->enabled);
        $this->assertEquals(TranslatablePropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function automaticTranslationCanBeEnabled(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse(<<<EOL
                options:
                    automaticTranslation: true
                EOL
            )
        );

        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(TranslatablePropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function automaticTranslationCanBeInherited(): void
    {
        $superType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:SuperType'),
            [],
            Yaml::parse(<<<EOL
                options:
                    automaticTranslation: true
                EOL
            )
        );
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            ['Neos.Neos:SuperType' => $superType],
            []
        );

        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(TranslatablePropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function inlineEditablePropertiesAreTranslatableUnlessDeactivated(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse(<<<EOL
                properties:
                    noText:
                        type: integer
                    textNotInlineEditable:
                        type: string
                        ui:
                            inlineEditable: false
                    textInlineEditableImplicitlyTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                    textInlineEditableExplicitlyNotTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                        options:
                           automaticTranslation: false
                    textInlineEditableExplicitlyTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                        options:
                           automaticTranslation: true
                options:
                    automaticTranslation: true
                EOL
            )
        );

        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(
            TranslatablePropertyNames::fromArray([
                new TranslatablePropertyName(PropertyName::fromString('textInlineEditableImplicitlyTranslated')),
                new TranslatablePropertyName(PropertyName::fromString('textInlineEditableExplicitlyTranslated'))
            ]),
            $directive->translatablePropertyNames
        );
    }

    /** @test */
    public function inlineEditablePropertiesAreTranslatableUnlessEnabled(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse(<<<EOL
                properties:
                    noText:
                        type: integer
                    textNotInlineEditable:
                        type: string
                        ui:
                            inlineEditable: false
                    textInlineEditableImplicitlyTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                    textInlineEditableExplicitlyNotTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                        options:
                           automaticTranslation: false
                    textInlineEditableExplicitlyTranslated:
                        type: string
                        ui:
                            inlineEditable: true
                        options:
                           automaticTranslation: true
                options:
                    automaticTranslation: true
                EOL
            )
        );

        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translateInlineEditables', false);

        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(
            TranslatablePropertyNames::fromArray([
                new TranslatablePropertyName(PropertyName::fromString('textInlineEditableExplicitlyTranslated'))
            ]),
            $directive->translatablePropertyNames
        );
    }

    public function detectionOfTranslatablePropertiesDataProvider(): \Generator
    {
        yield 'empty' => [
            new NodeType(NodeTypeName::fromString('Example'), [], []),
            new NodeTypeTranslationDirective(false, TranslatablePropertyNames::createEmpty()),
        ];

        yield 'explicitly enabled' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'options' => ['automaticTranslation' => true]
            ]),
            new NodeTypeTranslationDirective(true, TranslatablePropertyNames::createEmpty()),
        ];

        yield 'ignored' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'properties' => [
                    'stringProperty' => [
                        'type' => 'string',
                    ]
                ]
            ]),
            new NodeTypeTranslationDirective(false, TranslatablePropertyNames::createEmpty()),
        ];

        yield 'inline editable' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'properties' => [
                    'inlineEditableTextProperty' => [
                        'type' => 'string',
                        'ui' => [
                            'inlineEditable' => true,
                        ]
                    ]
                ]
            ]),
            new NodeTypeTranslationDirective(
                true,
                new TranslatablePropertyNames(
                    new TranslatablePropertyName(PropertyName::fromString('inlineEditableTextProperty')),
                ),
            ),
        ];

        yield 'inline editable but disabled' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'properties' => [
                    'inlineEditableTextProperty' => [
                        'type' => 'string',
                        'ui' => [
                            'inlineEditable' => true,
                        ]
                    ]
                ],
                'options' => ['automaticTranslation' => false]
            ]),
            new NodeTypeTranslationDirective(
                false,
                new TranslatablePropertyNames(
                    new TranslatablePropertyName(PropertyName::fromString('inlineEditableTextProperty')),
                ),
            ),
        ];

        yield 'automaticTranslation' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'properties' => [
                    'textPropertyWithOptions' => [
                        'type' => 'string',
                        'options' => [
                            'automaticTranslation' => true,
                        ]
                    ]
                ]
            ]),
            new NodeTypeTranslationDirective(
                true,
                new TranslatablePropertyNames(
                    new TranslatablePropertyName(PropertyName::fromString('textPropertyWithOptions')),
                ),
            )
        ];

        yield 'automaticTranslation but disabled' => [
            new NodeType(NodeTypeName::fromString('Example'), [], [
                'properties' => [
                    'textPropertyWithOptions' => [
                        'type' => 'string',
                        'options' => [
                            'automaticTranslation' => true,
                        ]
                    ]
                ],
                'options' => ['automaticTranslation' => false]
            ]),
            new NodeTypeTranslationDirective(
                false,
                new TranslatablePropertyNames(
                    new TranslatablePropertyName(PropertyName::fromString('textPropertyWithOptions')),
                ),
            )
        ];
    }

    /**
     * @dataProvider detectionOfTranslatablePropertiesDataProvider
     */
    public function testDetectionOfTranslatableProperties(NodeType $nodeType, NodeTypeTranslationDirective $expectedDirective): void
    {
        $this->assertEquals($expectedDirective, $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType));
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

        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'objectManager', $mockObjectManager);
        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translateTypesWithConnectors', true);
        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translationConnectors', ['Example\Class' => 'Example\TranslationConnector']);

        $nodeType = new NodeType(NodeTypeName::fromString('Example'), [], [
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

        $expectedDirective = new NodeTypeTranslationDirective(
            true,
            $expectedPropertyNames = new TranslatablePropertyNames(
                new TranslatablePropertyName(PropertyName::fromString('object'), $mockTranslationConnector)
            )
        );

        $this->assertEquals($expectedDirective, $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType));
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

        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'objectManager', $mockObjectManager);
        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translateTypesWithConnectors', false);
        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'translationConnectors', ['Example\Class' => 'Example\TranslationConnector']);

        $nodeType = new NodeType(NodeTypeName::fromString('Example'), [], [
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

        $expectedDirective = new NodeTypeTranslationDirective(
            true,
            new TranslatablePropertyNames(
                new TranslatablePropertyName(PropertyName::fromString('object'), $mockTranslationConnector)
            )
        );

        $this->assertEquals($expectedDirective, $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType));
    }

    public function testStringPropertyWithConfiguredPostProcessor(): void
    {
        $mockPostProcessor = $this->createMock(TranslatedPropertyPostProcessorInterface::class);

        $mockObjectManager = $this->createMock(ObjectManagerInterface::class);
        $mockObjectManager
            ->expects(self::once())
            ->method('get')
            ->with('Example\PostProcessor')
            ->willReturn($mockPostProcessor);

        $this->inject($this->nodeTypeTranslationDirectiveFactory, 'objectManager', $mockObjectManager);

        $nodeType = new NodeType(NodeTypeName::fromString('Example'), [], [
            'properties' => [
                'uriPathSegment' => [
                    'type' => 'string',
                    'options' => [
                        'automaticTranslation' => true,
                        'translationPostProcessor' => 'Example\PostProcessor',
                    ],
                ],
            ],
        ]);

        $expectedDirective = new NodeTypeTranslationDirective(
            true,
            new TranslatablePropertyNames(
                new TranslatablePropertyName(PropertyName::fromString('uriPathSegment'), null, $mockPostProcessor),
            ),
        );

        $this->assertEquals($expectedDirective, $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType));
    }
}
