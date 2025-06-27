<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Model;

use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Entity
 */
class GlossaryEntry
{
    /**
     * @var Glossary
     * @ORM\ManyToOne()
     */
    public $glossary;

    /**
     * @var string
     * @ORM\Column(type="text")
     */
    public $sourceText;

    /**
     * @var string
     * @ORM\Column(type="text")
     */
    public $targetText;
}
