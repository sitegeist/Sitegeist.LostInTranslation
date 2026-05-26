<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\Dummy;

use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\Domain\ApiStatus;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

#[Flow\Scope('singleton')]
class DummyTranslationService implements TranslationServiceInterface
{
    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null, bool $useCache = true): array
    {
        return array_map(
            fn (string $text): string => $text . ' translated',
            $texts,
        );
    }

    public function getStatus(): ApiStatus
    {
        return new ApiStatus(true);
    }

    public function getAIServiceId(): UserId
    {
        return UserId::fromString('AI:dummy:my-dummy');
    }
}
