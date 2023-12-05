<?php

namespace Sitegeist\LostInTranslation\Domain;

class TranslatableProperty
{
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
