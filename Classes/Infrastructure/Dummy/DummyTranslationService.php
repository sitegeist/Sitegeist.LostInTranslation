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
    /**
     * How many texts were handed to {@see self::translate()} since the last {@see self::resetTranslatedTextCount()}.
     *
     * Against the real DeepL service each of these is a billed character batch, which is what makes it worth
     * observing: `--dry-run` exists to estimate that cost, so it must decide what *would* be translated without
     * translating. Nothing else can detect a preview that quietly builds commands and discards them — the reported
     * counts look identical either way. Behat asserts on this counter.
     */
    public int $translatedTextCount = 0;

    public function translate(array $texts, string $targetLanguage, ?string $sourceLanguage = null): array
    {
        $this->translatedTextCount += count($texts);
        return array_map(
            fn (string $text): string => $text . ' translated',
            $texts,
        );
    }

    public function resetTranslatedTextCount(): void
    {
        $this->translatedTextCount = 0;
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
