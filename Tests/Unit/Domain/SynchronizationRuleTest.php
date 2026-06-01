<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\SynchronizationMode;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationScope;

class SynchronizationRuleTest extends UnitTestCase
{
    /**
     * @return array<string,string>
     */
    private function requiredFields(): array
    {
        return [
            'sourceWorkspaceName' => 'live',
            'sourceDimension' => 'en',
            'targetWorkspaceName' => 'live',
            'targetDimension' => 'es',
            'scope' => 'Document',
        ];
    }

    /** @test */
    public function fromArrayParsesAllFields(): void
    {
        $rule = SynchronizationRule::fromArray($this->requiredFields());

        self::assertSame('live', $rule->sourceWorkspaceName);
        self::assertSame('en', $rule->sourceDimension);
        self::assertSame('live', $rule->targetWorkspaceName);
        self::assertSame('es', $rule->targetDimension);
        self::assertSame(SynchronizationScope::Document, $rule->scope);
    }

    /** @test */
    public function fromArrayDefaultsModeToAuto(): void
    {
        $rule = SynchronizationRule::fromArray($this->requiredFields());

        self::assertSame(SynchronizationMode::Auto, $rule->mode);
    }

    /** @test */
    public function fromArrayParsesAskMode(): void
    {
        $rule = SynchronizationRule::fromArray(['mode' => 'ask'] + $this->requiredFields());

        self::assertSame(SynchronizationMode::Ask, $rule->mode);
    }

    /** @test */
    public function fromArrayRejectsUnknownMode(): void
    {
        $fields = $this->requiredFields();
        $fields['mode'] = 'maybe';

        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($fields);
    }

    /** @test */
    public function fromArrayParsesContentScope(): void
    {
        $rule = SynchronizationRule::fromArray(['scope' => 'Content'] + $this->requiredFields());

        self::assertSame(SynchronizationScope::Content, $rule->scope);
    }

    /** @test */
    public function fromArrayRejectsUnknownScope(): void
    {
        $fields = $this->requiredFields();
        $fields['scope'] = 'Everything';

        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($fields);
    }

    /** @test */
    public function fromArrayRejectsMissingScope(): void
    {
        $fields = $this->requiredFields();
        unset($fields['scope']);

        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($fields);
    }

    /** @test */
    public function fromArrayRejectsMissingRequiredField(): void
    {
        $fields = $this->requiredFields();
        unset($fields['targetDimension']);

        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($fields);
    }
}
