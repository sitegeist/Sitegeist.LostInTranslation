<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\Dimension\ContentDimension;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Factory\CommandHookFactoryInterface;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\FullWorkspaceSynchroniser;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SynchronisationRules;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

class PublicationSynchronisationCommandHookFactory implements CommandHookFactoryInterface
{
    #[Flow\InjectConfiguration(path: 'nodeTranslation.enabled')]
    public bool $enabled = false;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    /**
     * @var array<int,array<string,string>>
     */
    #[Flow\InjectConfiguration(path: 'nodeTranslation.synchronization')]
    public array $synchronization = [];

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        protected readonly StalePropertyCommandBuilder $stalePropertyCommandBuilder,
        protected readonly FullWorkspaceSynchroniser $fullWorkspaceSynchroniser,
        protected readonly TranslationServiceInterface $translationService,
        protected readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        $languageDimension = $commandHooksFactoryDependencies->contentDimensionSource->getDimension(
            new ContentDimensionId($this->languageDimensionName)
        );
        if (!($languageDimension instanceof ContentDimension)) {
            throw new \RuntimeException(sprintf(
                'Language dimension "%s" not found in content repository "%s"',
                $this->languageDimensionName,
                $commandHooksFactoryDependencies->contentRepositoryId->value,
            ), 1779052300);
        }

        // The CR is mid-construction during build(); resolve the stale-translation finder lazily
        // (inside `onAfterHandle`) to avoid the "Content repository was attempted to be build in
        // recursion" guard. By the time the hook actually fires, the CR is fully constructed.
        // Positional args: Flow's proxy generator wraps `__construct` with a no-params shim that
        // uses `func_get_args()`, so named parameters never reach the user-defined signature.
        return new PublicationSynchronisationCommandHook(
            $this->enabled,
            SynchronisationRules::fromArray($this->synchronization),
            $commandHooksFactoryDependencies->contentGraphReadModel,
            $commandHooksFactoryDependencies->nodeTypeManager,
            $this->contentRepositoryRegistry,
            $commandHooksFactoryDependencies->contentRepositoryId,
            $this->stalePropertyCommandBuilder,
            $this->fullWorkspaceSynchroniser,
            new DimensionValueDirectiveFactory(),
            $this->translationService,
            $languageDimension,
            $this->aiSystemTranslationRuntimeState,
        );
    }
}
