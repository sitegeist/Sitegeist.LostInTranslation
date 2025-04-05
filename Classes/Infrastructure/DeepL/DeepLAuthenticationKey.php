<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

class DeepLAuthenticationKey
{
    public readonly bool $isFree;
    public function __construct(
        public readonly string $authenticationKey,
        public readonly bool $isCustomKey = false,
        public readonly bool $isSettingKey = false,
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
