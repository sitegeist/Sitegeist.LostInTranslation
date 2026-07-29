<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * Outcome of a {@see Retranslator::retranslateSubtree()} call.
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
        /**
         * True when the skip is structural and recoverable only by a full sync: the node's ancestor
         * document is missing in the target dimension and has no stale record of its own, so the
         * stale-driven run cannot bootstrap it (the depth-sort only orders ancestors that *have* a
         * stale row). The CLI and backend module surface a "run synchronize --full" hint when set.
         * Implies `skippedReason !== null`.
         */
        public bool $requiresFullSync = false,
        /**
         * Commands dispatched to mirror a source-language deletion (or restore) into the target dimension: a
         * `TagSubtree` / `UntagSubtree` carrying the `removed` tag, which is how Neos 9.1 deletes. Only the manual /
         * full sync diff path emits these; the publish-driven hook emits its mirrors directly as additional commands.
         */
        public int $removalCommandsDispatched = 0,
        /**
         * `TagSubtree` / `UntagSubtree` commands dispatched to mirror source-language subtree-tag changes other than
         * deletion (e.g. hide/show) into the target dimension — only the manual / full sync diff path emits these.
         */
        public int $tagCommandsDispatched = 0,
    ) {
    }

    public static function skipped(string $reason): self
    {
        return new self(0, 0, $reason);
    }

    /**
     * A skip the caller can resolve by running a full sync — see {@see self::$requiresFullSync}.
     */
    public static function skippedRequiringFullSync(string $reason): self
    {
        return new self(0, 0, $reason, true);
    }

    /**
     * A node whose target variant was converged onto the source's subtree tags: `$removals` commands carrying the
     * `removed` tag (a mirrored deletion or restore) and `$tagChanges` other tag commands (hide/show, custom tags).
     */
    public static function mirrored(int $removals, int $tagChanges): self
    {
        return new self(0, 0, null, false, $removals, $tagChanges);
    }

    public function isNoOp(): bool
    {
        return $this->stalePropertyCommandsDispatched === 0
            && $this->variantCommandsDispatched === 0
            && $this->removalCommandsDispatched === 0
            && $this->tagCommandsDispatched === 0;
    }
}
