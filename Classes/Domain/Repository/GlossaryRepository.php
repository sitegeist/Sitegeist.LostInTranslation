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
        $query = $this->createQuery();
        $query = $query->matching($query->logicalAnd([
            $query->equals('sourceLanguageKey', strtoupper($sourceLanguageKey)),
            $query->equals('targetLanguageKey', strtoupper($targetLanguageKey))
        ]));
        /** @var Glossary|null $glossary */
        $glossary = $query->execute()->getFirst();
        return $glossary;
    }
}
