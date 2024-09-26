<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Repository;

use DateTime;
use Doctrine\DBAL\Types\Types;
use Neos\Flow\Annotations\Scope;
use Neos\Flow\Persistence\Doctrine\Query;
use Neos\Flow\Persistence\Exception\InvalidQueryException;
use Neos\Flow\Persistence\Repository;
use Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry;

#[Scope("singleton")]
class GlossaryEntryRepository extends Repository
{
    /**
     * @return GlossaryEntry[]
     */
    public function findByAggregateIdentifier(string $aggregateIdentifier): array
    {
        $query = $this->createQuery();

        $constraints = $query->logicalAnd(
            $query->equals('aggregateIdentifier', $aggregateIdentifier),
        );

        $query->matching($constraints);

        return $query->execute()->toArray();
    }

    /**
     * @param string[] $languages
     * @return GlossaryEntry[]
     * @throws InvalidQueryException
     */
    public function findByLanguages(array $languages): array
    {
        $query = $this->createQuery();

        $constraints = $query->logicalAnd(
            $query->in('glossaryLanguage', $languages),
        );

        $query->matching($constraints);

        return $query->execute()->toArray();
    }

    public function isTextInGlossary(string $text, string $glossaryLanguage): bool
    {
        $query = $this->createQuery();
        $queryBuilder = $query->getQueryBuilder();

        $result = $queryBuilder
            ->select('count(e.glossaryLanguage)')
            ->where('e.text = :text')
            ->andWhere('e.glossaryLanguage = :language')
            ->setParameter('text', $text, Types::STRING)
            ->setParameter('language', $glossaryLanguage, Types::STRING)
            ->groupBy('e.glossaryLanguage')
            ->getQuery()
            ->execute();

        return (bool) $result;
    }

    /**
     * @return string[]
     */
    public function findLanguagesThatRequireSyncing(DateTime $modifiedSince): array
    {
        /** @var Query $query */
        $query = $this
            ->createQuery()
        ;
        $queryBuilder = $query->getQueryBuilder();
        $queryBuilder->setParameter('modifiedSince', $modifiedSince, Types::DATETIME_MUTABLE);

        $result = $queryBuilder
            ->select('e.glossaryLanguage')
            ->where('e.lastModificationDateTime > :modifiedSince')
            ->groupBy('e.glossaryLanguage')
            ->getQuery()
            ->execute()
        ;

        return array_column($result, 'glossaryLanguage');
    }

    /**
     * @return array<string, DateTime>
     */
    public function getLanguagesLastModifiedAt(): array
    {
        /** @var Query $query */
        $query = $this
            ->createQuery()
        ;
        $queryBuilder = $query->getQueryBuilder();

        $rows = $queryBuilder
            ->select('e.glossaryLanguage AS language', 'MAX(e.lastModificationDateTime) AS date')
            ->groupBy('e.glossaryLanguage')
            ->getQuery()
            ->execute()
        ;

        $result = [];
        foreach ($rows as $row) {
            $result[$row['language']] = date_create_from_format('Y-m-d H:i:s', $row['date']);
        }

        return $result;
    }
}
