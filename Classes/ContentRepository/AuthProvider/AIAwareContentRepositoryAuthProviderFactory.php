<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\AuthProvider;

use Neos\ContentRepository\Core\Factory\AuthProviderFactoryInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Security\Context as SecurityContext;
use Neos\Neos\Domain\Service\UserService;
use Neos\Neos\Security\Authorization\ContentRepositoryAuthorizationService;
use Neos\Neos\Security\ContentRepositoryAuthProvider\ContentRepositoryAuthProvider;

/**
 * Implementation of the {@see AuthProviderFactoryInterface} in order to provide authentication and authorization for Content Repositories
 * and distinguish between human and AI editors
 *
 * @api
 */
#[Flow\Scope('singleton')]
final readonly class AIAwareContentRepositoryAuthProviderFactory implements AuthProviderFactoryInterface
{
    public function __construct(
        private UserService $userService,
        private ContentRepositoryAuthorizationService $contentRepositoryAuthorizationService,
        private SecurityContext $securityContext,
        private AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    public function build(
        ContentRepositoryId $contentRepositoryId,
        ContentGraphReadModelInterface $contentGraphReadModel
    ): AIAwareContentRepositoryAuthProvider {
        return new AIAwareContentRepositoryAuthProvider(
            baseAuthProvider: new ContentRepositoryAuthProvider(
                $contentRepositoryId,
                $this->userService,
                $contentGraphReadModel,
                $this->contentRepositoryAuthorizationService,
                $this->securityContext
            ),
            aiSystemTranslationRuntimeState: $this->aiSystemTranslationRuntimeState,
        );
    }
}
