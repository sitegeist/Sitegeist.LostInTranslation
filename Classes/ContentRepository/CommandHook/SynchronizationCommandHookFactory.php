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
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\SynchronizationRules;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

class SynchronizationCommandHookFactory implements CommandHookFactoryInterface
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

    protected ?LoggerInterface $logger = null;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        protected readonly StalePropertyCommandBuilder $stalePropertyCommandBuilder,
        protected readonly TranslationServiceInterface $translationService,
        protected readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
    ) {
    }

    /**
     * Setter injection rather than a constructor argument: the CR builds this factory through the object manager, and
     * the logger is optional for the hook (see its constructor).
     */
    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        $languageDimension = $commandHooksFactoryDependencies->contentDimensionSource->getDimension(
            new ContentDimensionId($this->languageDimensionName)
        );
        if (!($languageDimension instanceof ContentDimension)) {
            // Switched off (globally, or by having no rules — the same pair the hook itself early-outs on): the hook
            // could not do anything even with a dimension, so do not make a CR that lacks one unbuildable. See
            // {@see DisabledCommandHook}. Switched on, a missing dimension stays a hard failure.
            //
            // Checked here as well as in TranslationCommandHookFactory although the shipped preset registers that hook
            // first, so in practice it decides this case: a factory should not depend on a sibling's registration order
            // for its own correctness, and either hook can be disabled individually via `commandHooks.<name>: ~`.
            if (!$this->enabled || $this->synchronization === []) {
                return new DisabledCommandHook();
            }
            throw new \RuntimeException(sprintf(
                'Language dimension "%s" not found in content repository "%s"',
                $this->languageDimensionName,
                $commandHooksFactoryDependencies->contentRepositoryId->value,
            ), 1779052300);
        }

        // The CR is mid-construction during build(); resolve the stale-translation finder lazily (inside
        // `onAfterHandle`) to avoid the "Content repository was attempted to be build in recursion" guard. By the time
        // the hook actually fires, the CR is fully constructed.
        //
        // Positional args, DO NOT "fix" to named: Flow's proxy generator wraps `__construct` with a no-params shim
        // that uses `func_get_args()`, so named parameters never reach the user-defined signature — a named-args
        // refactor compiles fine and then mis-binds every argument at runtime.
        return new SynchronizationCommandHook(
            $this->enabled,
            SynchronizationRules::fromArray($this->synchronization),
            $commandHooksFactoryDependencies->contentGraphReadModel,
            $commandHooksFactoryDependencies->nodeTypeManager,
            $this->contentRepositoryRegistry,
            $commandHooksFactoryDependencies->contentRepositoryId,
            $this->stalePropertyCommandBuilder,
            new DimensionValueDirectiveFactory(),
            $this->translationService,
            $languageDimension,
            $this->aiSystemTranslationRuntimeState,
            $this->logger,
        );
    }
}
