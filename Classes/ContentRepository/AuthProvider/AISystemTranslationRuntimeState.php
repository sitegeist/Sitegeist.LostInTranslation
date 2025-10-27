<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\AuthProvider;

use Neos\Flow\Annotations as Flow;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;

/**
 * The state tracking if an AI system - and which one - is currently running a translation
 */
#[Flow\Scope('singleton')]
final class AISystemTranslationRuntimeState
{
    public function __construct(
        private ?UserId $activeAIServiceId = null
    ) {
    }

    public function setActiveAIServiceId(UserId $aiServiceId): void
    {
        $this->activeAIServiceId = $aiServiceId;
    }

    public function getActiveAIServiceId(): ?UserId
    {
        return $this->activeAIServiceId;
    }

    public function resetActiveAIServiceId(): void
    {
        $this->activeAIServiceId = null;
    }
}
