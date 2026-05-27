<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\Domain;

use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationStrategy;
use Sitegeist\LostInTranslation\Domain\TranslationStrategy;

class SynchronizationRuleTest extends UnitTestCase
{
    /**
     * @return array<string,string>
     */
    private function requiredFields(): array
    {
        return [
            'sourceWorkspaceName' => 'live',
            'sourceLanguage' => 'en',
            'targetWorkspaceName' => 'live',
            'targetLanguage' => 'es',
        ];
    }

    /** @test */
    public function fromArrayDefaultsToStaleAndKeepExistingWhenStrategyFieldsAreOmitted(): void
    {
        $rule = SynchronizationRule::fromArray($this->requiredFields());

        self::assertSame(SynchronizationStrategy::Stale, $rule->synchronizationStrategy);
        self::assertSame(TranslationStrategy::KeepExisting, $rule->translationStrategy);
    }

    /** @test */
    public function fromArrayParsesExplicitStrategies(): void
    {
        $rule = SynchronizationRule::fromArray($this->requiredFields() + [
            'synchronizationStrategy' => 'full',
            'translationStrategy' => 'force-refresh',
        ]);

        self::assertSame(SynchronizationStrategy::Full, $rule->synchronizationStrategy);
        self::assertSame(TranslationStrategy::ForceRefresh, $rule->translationStrategy);
    }

    /** @test */
    public function fromArrayRejectsUnknownSynchronizationStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($this->requiredFields() + ['synchronizationStrategy' => 'sometimes']);
    }

    /** @test */
    public function fromArrayRejectsUnknownTranslationStrategy(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($this->requiredFields() + ['translationStrategy' => 'maybe']);
    }

    /** @test */
    public function fromArrayRejectsMissingRequiredField(): void
    {
        $fields = $this->requiredFields();
        unset($fields['targetLanguage']);

        $this->expectException(\InvalidArgumentException::class);
        SynchronizationRule::fromArray($fields);
    }
}
