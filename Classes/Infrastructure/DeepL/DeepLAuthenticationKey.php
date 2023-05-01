<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Infrastructure\DeepL;

class DeepLAuthenticationKey
{
    /**
     * @var string
     */
    protected $authenticationKey;

    /**
     * @var bool
     */
    protected $isFree;

    public function __construct(string $authenticationKey)
    {
        if (empty($authenticationKey)) {
            throw new \InvalidArgumentException('Empty strings are not allowed as authentication key');
        }

        $this->authenticationKey = $authenticationKey;
        $this->isFree = substr($authenticationKey, -3, 3) === ':fx';
    }

    /**
     * @return string
     */
    public function getAuthenticationKey(): string
    {
        return $this->authenticationKey;
    }

    /**
     * @return bool
     */
    public function isFree(): bool
    {
        return $this->isFree;
    }

    /**
     * @return string
     */
    public function __toString(): string
    {
        return $this->authenticationKey;
    }
}
