<?php

namespace Sitegeist\LostInTranslation\Domain;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class ReplaceTerm
{
    private string $original;
    private string $translation;

    public function __construct(string $original, string $translation)
    {
        $this->original = $original;
        $this->translation = $translation;
    }

    public function getOriginal(): string
    {
        return $this->original;
    }

    public function getTranslation(): string
    {
        return $this->translation;
    }
}
