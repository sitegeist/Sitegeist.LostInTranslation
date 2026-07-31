<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\PostProcessor;

use Neos\Flow\Annotations as Flow;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;

/**
 * Keeps a translated `uriPathSegment` a valid URL slug.
 *
 * A uriPathSegment has a strict charset (`[a-z0-9-]`); DeepL routinely violates it (spaces, accents, casing). When the
 * translated value is not already a valid slug it is regenerated via Neos' {@see NodeUriPathSegmentGenerator}; valid
 * values pass through unchanged so the operation is idempotent.
 */
#[Flow\Scope('singleton')]
class UriPathSegmentPostProcessor implements TranslatedPropertyPostProcessorInterface
{
    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator;

    public function process(string $translatedValue): string
    {
        if (preg_match('/^[a-z0-9\-]+$/i', $translatedValue)) {
            return $translatedValue;
        }
        return $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
    }
}
