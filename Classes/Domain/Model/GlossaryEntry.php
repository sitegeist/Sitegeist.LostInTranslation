<?php
declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Model;

use DateTime;
use Doctrine\ORM\Mapping as ORM;
use JsonSerializable;
use Neos\Flow\Annotations as Flow;

/**
 * A glossary entry model that represents the entity that is used to store the glossary data internally.
 *
 * @Flow\Entity
 * @ORM\Table(
 *    name="sitegeist_lostintranslation_domain_model_glossaryentry",
 *    uniqueConstraints={
 *      @ORM\UniqueConstraint(name="flow_identity_sitegeist_lostintranslation_databasestorage_domain_model_glossaryentry",columns={"aggregateidentifier", "glossarylanguage"})
 *    },
 *    indexes={
 * 		@ORM\Index(name="entry",columns={"aggregateidentifier"})
 *    }
 * )
 */
class GlossaryEntry
{
    /**
     * UUID4 identifier that groups entities with a single language
     * to the actual entry with multiple languages.
     *
     * @var string
     * @ORM\Column(length=36)
     */
    public string $aggregateIdentifier;
    /**
     * @var DateTime
     */
    public DateTime $lastModificationDateTime;
    /**
     * Uppercase 2-digit ISO code as supported by DeepL for glossaries:
     * https://www.deepl.com/de/docs-api/glossaries/
     *
     * @var string
     * @ORM\Column(length=2)
     */
    public string $glossaryLanguage;

    /**
     * @var string
     * @ORM\Column(length=500)
     */
    public string $text;

    public function __construct(
        string   $aggregateIdentifier,
        DateTime $lastModificationDateTime,
        string   $glossaryLanguage,
        string   $text,
    ) {
        $this->aggregateIdentifier = $aggregateIdentifier;
        $this->lastModificationDateTime = $lastModificationDateTime;
        $this->glossaryLanguage = strtoupper($glossaryLanguage);
        $this->text = $text;
    }

}
