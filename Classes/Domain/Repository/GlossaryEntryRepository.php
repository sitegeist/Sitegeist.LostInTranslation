<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Repository;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Persistence\Repository;
use Sitegeist\LostInTranslation\Domain\Model\Glossary;

/**
 * @Flow\Scope("singleton")
 */
class GlossaryEntryRepository extends Repository
{
}
