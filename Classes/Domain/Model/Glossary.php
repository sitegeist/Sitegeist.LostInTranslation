<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use League\Csv\Writer;

/**
 * @Flow\Entity
 * @ORM\Table(uniqueConstraints={@ORM\UniqueConstraint(name="languageCombination", columns={"sourceLanguageKey", "targetLanguageKey"})})
 */
class Glossary
{
    /**
     * @var string
     */
    public string $sourceLanguageKey;

    /**
     * @var string
     */
    public string $targetLanguageKey;

    /**
     * @var \DateTimeImmutable|null
     * @ORM\Column(nullable=true)
     */
    public $synchronizationDate;

    /**
     * @var string|null
     * @ORM\Column(nullable=true)
     */
    public $synchronizationIdentifier;

    /**
     * @var \DateTimeImmutable
     */
    public $modificationDate;

    /**
     * @phpstan-var Collection<int, GlossaryEntry>
     * @var Collection<GlossaryEntry>
     * @ORM\OneToMany(targetEntity="Sitegeist\LostInTranslation\Domain\Model\GlossaryEntry", mappedBy="glossary", cascade={"persist"})
     */
    public $entries;

    public static function create(string $source, string $target): Glossary
    {
        $subject = new Glossary();
        $subject->entries = new ArrayCollection();
        $subject->sourceLanguageKey = strtoupper($source);
        $subject->targetLanguageKey = strtoupper($target);
        $subject->modificationDate = new \DateTimeImmutable();
        return $subject;
    }

    public function getLabel(): string
    {
        return $this->sourceLanguageKey . ' -> ' . $this->targetLanguageKey;
    }

    public function isUpToDate(): bool
    {
        return $this->modificationDate <= $this->synchronizationDate;
    }

    public function addEntry(GlossaryEntry $entry): void
    {
        $this->modificationDate = new \DateTimeImmutable();
        $this->entries->add($entry);
    }

    public function updateSynchronizationIdentifier(string $id): void
    {
        $this->synchronizationDate = new \DateTimeImmutable();
        $this->synchronizationIdentifier = $id;
    }

    public function removeEntry(GlossaryEntry $entry): void
    {
        $this->modificationDate = new \DateTimeImmutable();
        $this->entries->removeElement($entry);
    }

    /**
     * @return array<string, string>
     */
    public function getEntriesAsAssociativeArray(): array
    {
        $entries = [];
        foreach ($this->entries as $entry) {
            $entries[$entry->sourceText] = $entry->targetText;
        }
        return $entries;
    }
}
