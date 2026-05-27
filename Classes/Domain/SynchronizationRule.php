<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * One rule of `Sitegeist.LostInTranslation.nodeTranslation.synchronization`. Reads as:
 * "When a publish lands on `sourceWorkspaceName`, auto-translate (`targetWorkspaceName`,
 * `targetLanguage`) from `sourceLanguage`."
 *
 * `synchronizationStrategy` picks *which* nodes are visited (stale records vs the whole subtree);
 * `translationStrategy` picks what happens to a visited node whose target variant already exists.
 * See the respective enums for the exact semantics (including the "stale always forces a refresh"
 * override).
 */
#[Flow\Proxy(false)]
final readonly class SynchronizationRule
{
    public function __construct(
        public string $sourceWorkspaceName,
        public string $sourceLanguage,
        public string $targetWorkspaceName,
        public string $targetLanguage,
        public SynchronizationStrategy $synchronizationStrategy = SynchronizationStrategy::Stale,
        public TranslationStrategy $translationStrategy = TranslationStrategy::KeepExisting,
    ) {
    }

    /**
     * @param array<string,string> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['sourceWorkspaceName', 'sourceLanguage', 'targetWorkspaceName', 'targetLanguage'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
                throw new \InvalidArgumentException(sprintf('SynchronizationRule is missing required string field "%s"', $key), 1779051200);
            }
        }

        $synchronizationStrategyValue = $row['synchronizationStrategy'] ?? SynchronizationStrategy::Stale->value;
        $synchronizationStrategy = SynchronizationStrategy::tryFrom($synchronizationStrategyValue);
        if ($synchronizationStrategy === null) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid synchronizationStrategy "%s" in SynchronizationRule; expected one of: %s',
                $synchronizationStrategyValue,
                implode(', ', array_map(static fn (SynchronizationStrategy $s): string => $s->value, SynchronizationStrategy::cases())),
            ), 1779051300);
        }

        $translationStrategyValue = $row['translationStrategy'] ?? TranslationStrategy::KeepExisting->value;
        $translationStrategy = TranslationStrategy::tryFrom($translationStrategyValue);
        if ($translationStrategy === null) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid translationStrategy "%s" in SynchronizationRule; expected one of: %s',
                $translationStrategyValue,
                implode(', ', array_map(static fn (TranslationStrategy $s): string => $s->value, TranslationStrategy::cases())),
            ), 1779051301);
        }

        return new self(
            sourceWorkspaceName: $row['sourceWorkspaceName'],
            sourceLanguage: $row['sourceLanguage'],
            targetWorkspaceName: $row['targetWorkspaceName'],
            targetLanguage: $row['targetLanguage'],
            synchronizationStrategy: $synchronizationStrategy,
            translationStrategy: $translationStrategy,
        );
    }
}
