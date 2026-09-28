<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

enum StringTranslationMode: string
{
    case Plain = 'plain';
    case Html = 'html';
}
