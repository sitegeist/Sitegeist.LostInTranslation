<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\PostProcessor;

/**
 * Transforms a freshly translated scalar property value before it is written to the target node.
 *
 * Configured per node-type property via `properties.<name>.options.translationPostProcessor`, e.g. a `uriPathSegment`
 * whose translation must be coerced back into a valid URL slug. Implementations are resolved through the Flow object
 * manager, so they may declare dependencies.
 */
interface TranslatedPropertyPostProcessorInterface
{
    public function process(string $translatedValue): string;
}
