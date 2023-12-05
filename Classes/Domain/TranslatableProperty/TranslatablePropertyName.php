<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain\TranslatableProperty;

class TranslatablePropertyName
{
    /**
     * @var string
     */
    protected $name;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }
}
