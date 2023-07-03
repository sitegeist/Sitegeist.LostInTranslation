<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

class ApiStatus
{
    /**
     * @var bool
     */
    protected $connectionSuccessFull = false;

    /**
     * @var int
     */
    protected $characterCount = 0;

    /**
     * @var int
     */
    protected $characterLimit = 0;

    /**
     * @var bool
     */
    protected $hasSettingsKey = false;

    /**
     * @var bool
     */
    protected $hasCustomKey = false;

    /**
     * @var bool
     */
    protected $isFreeApi = false;

    public function __construct(
        bool $connectionSuccessFull,
        int $characterCount = 0,
        int $characterLimit = 0,
        bool $hasSettingsKey = false,
        bool $hasCustomKey = false,
        bool $isFreeApi = false
    ) {
        $this->connectionSuccessFull = $connectionSuccessFull;
        $this->characterCount = $characterCount;
        $this->characterLimit = $characterLimit;
        $this->hasSettingsKey = $hasSettingsKey;
        $this->hasCustomKey = $hasCustomKey;
        $this->isFreeApi = $isFreeApi;
    }

    /**
     * @return bool
     */
    public function isConnectionSuccessFull(): bool
    {
        return $this->connectionSuccessFull;
    }

    /**
     * @return int
     */
    public function getCharacterCount(): int
    {
        return $this->characterCount;
    }

    /**
     * @return int
     */
    public function getCharacterLimit(): int
    {
        return $this->characterLimit;
    }

    /**
     * @return bool
     */
    public function isHasSettingsKey(): bool
    {
        return $this->hasSettingsKey;
    }

    /**
     * @return bool
     */
    public function isHasCustomKey(): bool
    {
        return $this->hasCustomKey;
    }

    /**
     * @return bool
     */
    public function isFreeApi(): bool
    {
        return $this->isFreeApi;
    }
}
