<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\AuthProvider;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\Feature\Security\AuthProviderInterface;
use Neos\ContentRepository\Core\Feature\Security\Dto\Privilege;
use Neos\ContentRepository\Core\Feature\Security\Dto\UserId;
use Neos\ContentRepository\Core\Projection\ContentGraph\VisibilityConstraints;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;

#[Flow\Proxy(false)]
final class AIAwareContentRepositoryAuthProvider implements AuthProviderInterface
{
    public function __construct(
        private readonly AuthProviderInterface $baseAuthProvider,
        private readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    public function getAuthenticatedUserId(): ?UserId
    {
        return $this->aiSystemTranslationRuntimeState->getActiveAIServiceId()
            ?: $this->baseAuthProvider->getAuthenticatedUserId();
    }

    public function canReadNodesFromWorkspace(WorkspaceName $workspaceName): Privilege
    {
        return $this->baseAuthProvider->canReadNodesFromWorkspace($workspaceName);
    }

    public function getVisibilityConstraints(WorkspaceName $workspaceName): VisibilityConstraints
    {
        return $this->baseAuthProvider->getVisibilityConstraints($workspaceName);
    }

    public function canExecuteCommand(CommandInterface $command): Privilege
    {
        return $this->baseAuthProvider->canExecuteCommand($command);
    }
}
