<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\AuthProvider;

use Neos\ContentRepository\Core\Factory\AuthProviderFactoryInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\TestSuite\Fakes\FakeAuthProvider;
use Neos\Flow\Annotations as Flow;

/**
 * Implementation of the {@see AuthProviderFactoryInterface} in order to provide authentication and authorization for Content Repositories
 * and distinguish between human and AI editors
 *
 * @api
 */
#[Flow\Scope('singleton')]
final readonly class AIAwareFakeAuthProviderFactory implements AuthProviderFactoryInterface
{
    public function __construct(
        private AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    public function build(
        ContentRepositoryId $contentRepositoryId,
        ContentGraphReadModelInterface $contentGraphReadModel
    ): AIAwareContentRepositoryAuthProvider {
        return new AIAwareContentRepositoryAuthProvider(
            baseAuthProvider: new FakeAuthProvider(),
            aiSystemTranslationRuntimeState: $this->aiSystemTranslationRuntimeState,
        );
    }
}
