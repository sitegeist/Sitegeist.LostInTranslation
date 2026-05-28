<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;

interface TranslationServiceInterface
{
    /**
     * Translate every value in `$texts`, preserving keys. When `$useCache` is false the implementation MUST skip its
     * translation cache for both reads (no shortcut return) and writes (no cache pollution from this call). Used by
     * "force re-translate" tooling.
     *
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @param bool $useCache
     * @return array<string,string>
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null, bool $useCache = true): array;

    public function getStatus(): ApiStatus;

    public function getAIServiceId(): UserId;
}
