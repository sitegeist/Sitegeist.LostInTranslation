<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Model;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Neos\Flow\Annotations as Flow;
use League\Csv\Writer;

#[Flow\Proxy(false)]
readonly class GlossaryLanguageKeys
{
    /**
     * @param string[] $sourceLanguages
     * @param string[] $targetLanguages
     */
    public function __construct (
        public array $sourceLanguages,
        public array $targetLanguages
    ) {

    }
}
