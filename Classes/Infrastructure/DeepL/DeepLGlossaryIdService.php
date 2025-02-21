<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

use Sitegeist\LostInTranslation\Domain\Repository\GlossaryRepository;
use Neos\Flow\Annotations as Flow;

class DeepLGlossaryIdService
{
    /**
     * @var GlossaryRepository
     * @Flow\Inject
     */
    public $glossaryRepository;

    public function findGlossaryId(string $sourceLanguage, string $targetLanguage): ?string
    {
        $glossary = $this->glossaryRepository->findOneBySourceAndTargetLanguageKey($sourceLanguage, $targetLanguage);
        return $glossary?->synchronizationIdentifier;
    }
}
