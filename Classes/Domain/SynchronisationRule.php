<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * One rule of `Sitegeist.LostInTranslation.nodeTranslation.synchronization`. Reads as:
 * "When a publish lands on `sourceWorkspaceName`, auto-translate stale records in
 * (`targetWorkspaceName`, `targetLanguage`) from `sourceLanguage`."
 */
#[Flow\Proxy(false)]
final readonly class SynchronisationRule
{
    public function __construct(
        public string $sourceWorkspaceName,
        public string $sourceLanguage,
        public string $targetWorkspaceName,
        public string $targetLanguage,
    ) {
    }

    /**
     * @param array<string,string> $row
     */
    public static function fromArray(array $row): self
    {
        foreach (['sourceWorkspaceName', 'sourceLanguage', 'targetWorkspaceName', 'targetLanguage'] as $key) {
            if (!isset($row[$key]) || !is_string($row[$key]) || $row[$key] === '') {
                throw new \InvalidArgumentException(sprintf('SynchronisationRule is missing required string field "%s"', $key), 1779051200);
            }
        }
        return new self(
            sourceWorkspaceName: $row['sourceWorkspaceName'],
            sourceLanguage: $row['sourceLanguage'],
            targetWorkspaceName: $row['targetWorkspaceName'],
            targetLanguage: $row['targetLanguage'],
        );
    }
}
