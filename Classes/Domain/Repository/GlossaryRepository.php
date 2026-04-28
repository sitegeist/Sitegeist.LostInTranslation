<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;

/**
 * @Flow\Scope("singleton")
 */
class GlossaryRepository extends Repository
{
    public function findOneBySourceAndTargetLanguageKey(string $sourceLanguageKey, string $targetLanguageKey): ?Glossary
    {
        // at the moment glossaries have only the language part and no country
        $sourceLanguageKeyParts = explode('-', $sourceLanguageKey);
        $targetLanguageKeyParts = explode('-', $targetLanguageKey);

        $query = $this->createQuery();
        $query = $query->matching($query->logicalAnd([
            $query->equals('sourceLanguageKey', strtoupper($sourceLanguageKeyParts[0])),
            $query->equals('targetLanguageKey', strtoupper($targetLanguageKeyParts[0]))
        ]));
        /** @var Glossary|null $glossary */
        $glossary = $query->execute()->getFirst();
        return $glossary;
    }
}
