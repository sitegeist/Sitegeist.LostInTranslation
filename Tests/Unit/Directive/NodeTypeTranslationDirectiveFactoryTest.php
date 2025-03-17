<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Directive;

use Neos\ContentRepository\Core\NodeType\NodeType;
use Neos\ContentRepository\Core\NodeType\NodeTypeName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyName;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Symfony\Component\Yaml\Yaml;

class NodeTypeTranslationDirectiveFactoryTest extends UnitTestCase
{
    protected NodeTypeTranslationDirectiveFactory $subject;
    public function setUp(): void
    {
        $this->subject = new NodeTypeTranslationDirectiveFactory();
        $this->inject($this->subject, 'translateInlineEditables', true);
    }

    /** @test */
    public function automaticTranslationCanBeDisabled(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse( <<<EOL
                options:
                    automaticTranslation: false
                EOL
            )
        );

        $directive = $this->subject->createForNodeType($nodeType);
        $this->assertEquals(false, $directive->enabled);
        $this->assertEquals(PropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function automaticTranslationCanBeEnabled(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse( <<<EOL
                options:
                    automaticTranslation: true
                EOL
            )
        );

        $directive = $this->subject->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(PropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function automaticTranslationCanBeInherited(): void
    {
        $superType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:SuperType'),
            [],
            Yaml::parse( <<<EOL
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

        $directive = $this->subject->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(PropertyNames::createEmpty(), $directive->translatablePropertyNames);
    }

    /** @test */
    public function inlineEditablePropertiesAreTranslatableUnlessDeactivated(): void
    {
        $nodeType = new NodeType(
            NodeTypeName::fromString('Neos.Neos:NodeType'),
            [],
            Yaml::parse( <<<EOL
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

        $directive = $this->subject->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(
            PropertyNames::fromArray([
                PropertyName::fromString('textInlineEditableImplicitlyTranslated'),
                PropertyName::fromString('textInlineEditableExplicitlyTranslated')
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
            Yaml::parse( <<<EOL
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

        $this->inject($this->subject, 'translateInlineEditables', false);

        $directive = $this->subject->createForNodeType($nodeType);
        $this->assertEquals(true, $directive->enabled);
        $this->assertEquals(
            PropertyNames::fromArray([
                PropertyName::fromString('textInlineEditableExplicitlyTranslated')
            ]),
            $directive->translatablePropertyNames
        );
    }
}
