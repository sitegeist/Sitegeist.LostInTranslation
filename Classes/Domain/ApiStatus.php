<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

class ApiStatus
{
    public function __construct(
        public readonly bool $connectionSuccessFull,
        public readonly int $characterCount = 0,
        public readonly int $characterLimit = 0,
        public readonly bool $hasSettingsKey = false,
        public readonly bool $hasCustomKey = false,
        public readonly bool $isFreeApi = false,
        public readonly bool $limitIsReached = false,
    ) {
    }

    /**
     * @return bool
     * @deprecated
     */
    public function isConnectionSuccessFull(): bool
    {
        return $this->connectionSuccessFull;
    }

    /**
     * @return int
     * @deprecated
     */
    public function getCharacterCount(): int
    {
        return $this->characterCount;
    }

    /**
     * @return int
     * @deprecated
     */
    public function getCharacterLimit(): int
    {
        return $this->characterLimit;
    }

    /**
     * @return bool
     * @deprecated
     */
    public function isHasSettingsKey(): bool
    {
        return $this->hasSettingsKey;
    }

    /**
     * @return bool
     * @deprecated
     */
    public function isHasCustomKey(): bool
    {
        return $this->hasCustomKey;
    }

    /**
     * @return bool
     * @deprecated
     */
    public function isFreeApi(): bool
    {
        return $this->isFreeApi;
    }
}
