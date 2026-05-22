<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;

class NodeTypeTranslationDirective
{
    public function __construct(
        public readonly bool $enabled,
        public readonly TranslatablePropertyNames $translatablePropertyNames,
    ) {
    }

    public function getPropertyNames(): PropertyNames
    {
        return PropertyNames::fromArray(array_keys($this->translatablePropertyNames->items));
    }
}
