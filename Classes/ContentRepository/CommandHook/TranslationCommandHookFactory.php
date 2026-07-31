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
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\StalePropertyCommandBuilder;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

class TranslationCommandHookFactory implements CommandHookFactoryInterface
{
    #[Flow\InjectConfiguration(path:'nodeTranslation.enabled')]
    public bool $enabled = false;

    #[Flow\InjectConfiguration(path:'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        protected readonly NodeTypeTranslationDirectiveFactory $translatablePropertyNamesFactory,
        protected readonly TranslationServiceInterface $translationService,
        protected readonly AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState,
        protected readonly StalePropertyCommandBuilder $stalePropertyCommandBuilder,
    ) {
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        $languageDimension = $commandHooksFactoryDependencies->contentDimensionSource->getDimension(
            new ContentDimensionId($this->languageDimensionName)
        );

        if (!($languageDimension instanceof ContentDimension) && !$this->enabled) {
            // Explicitly switched off: the hook would be inert anyway, so a CR without the language dimension must
            // stay buildable rather than be bricked by a package the operator has disabled. See
            // {@see DisabledCommandHook}. With the feature on, the throw below stands.
            return new DisabledCommandHook();
        }

        if ($languageDimension instanceof ContentDimension) {
            return new TranslationCommandHook(
                $this->enabled,
                $commandHooksFactoryDependencies->contentGraphReadModel,
                $commandHooksFactoryDependencies->nodeTypeManager,
                $this->translatablePropertyNamesFactory,
                new DimensionValueDirectiveFactory(),
                $this->translationService,
                $languageDimension,
                $this->aiSystemTranslationRuntimeState,
                $this->stalePropertyCommandBuilder,
            );
        } else {
            throw new \Exception(sprintf('Language dimension %s was not found in content repository %s', $this->languageDimensionName, $commandHooksFactoryDependencies->contentRepositoryId->value));
        }
    }
}
