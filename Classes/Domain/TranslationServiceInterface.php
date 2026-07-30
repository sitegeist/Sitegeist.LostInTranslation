<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;

interface TranslationServiceInterface
{
    /**
     * Translate every value in `$texts`, preserving keys.
     *
     * @param array<string,string> $texts
     * @param string $targetLanguage
     * @param string|null $sourceLanguage
     * @return array<string,string>
     */
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array;

    public function getStatus(): ApiStatus;

    public function getAIServiceId(): UserId;
}
