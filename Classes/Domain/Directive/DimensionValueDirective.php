<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\Directive;

class DimensionValueDirective
{
    public function __construct(
        public readonly ?string $deeplSourceId,
        public readonly ?string $deeplTargetId,
    ) {
    }
}
