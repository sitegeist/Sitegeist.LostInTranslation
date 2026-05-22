<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * Outcome of a {@see Retranslator::retranslateNode()} call.
 *
 * Returned (rather than `void`) so callers — especially the CLI — can distinguish a real successful
 * retranslation from a silent no-op caused by a configuration skip path (no `referenceLanguage`,
 * `deeplLanguage: false`, source node missing, etc.). The previous void return forced the CLI to
 * print "Retranslation finished" even when zero commands had been dispatched, which was misleading.
 *
 * Counts reflect commands actually handed to `ContentRepository::handle()`. They do NOT include
 * cascaded events emitted by `TranslationCommandHook` in response to our `CreateNodeVariant`
 * dispatches — that follow-up work is the hook's bookkeeping, not ours.
 */
#[Flow\Proxy(false)]
final readonly class RetranslationResult
{
    public function __construct(
        /**
         * Direct `SetNodeProperties` commands dispatched against existing target-language variants
         * to refresh translated property values that the projection flagged as stale.
         */
        public int $stalePropertyCommandsDispatched,
        /**
         * `CreateNodeVariant` commands dispatched for source-language nodes that had no variant in
         * the target language yet. Each of these triggers `TranslationCommandHook` to cascade
         * one or more additional `SetNodeProperties` events.
         */
        public int $variantCommandsDispatched,
        /**
         * Set when the call short-circuited before doing any work — e.g. the target DSP has no
         * `referenceLanguage` configured, the DeepL language is disabled for source or target, or
         * the source node could not be located. Non-null implies both counts are zero.
         */
        public ?string $skippedReason = null,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self(0, 0, $reason);
    }

    public function isNoOp(): bool
    {
        return $this->stalePropertyCommandsDispatched === 0 && $this->variantCommandsDispatched === 0;
    }
}
