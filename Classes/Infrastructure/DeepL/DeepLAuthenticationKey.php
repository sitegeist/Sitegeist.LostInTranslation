<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

class DeepLAuthenticationKey
{
    public bool $isFree;
    public function __construct(
        public readonly string $authenticationKey,
        public readonly bool $isCustomKey = false
    ) {
        $this->isFree = str_ends_with($authenticationKey, ':fx');
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->authenticationKey;
    }
}
