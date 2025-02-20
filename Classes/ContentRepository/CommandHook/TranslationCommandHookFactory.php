<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\Factory\CommandHookFactoryInterface;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\ContentRepository\Core\Projection\CatchUpHook\CatchUpHookFactoryInterface;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\FrontendRouting\Projection\DocumentUriPathFinder;
use Sitegeist\LostInTranslation\Domain\TranslatableProperty\TranslatablePropertyNamesFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * @implements CatchUpHookFactoryInterface<DocumentUriPathFinder>
 */
class TranslationCommandHookFactory implements CommandHookFactoryInterface
{

    #[Flow\InjectConfiguration(path:'nodeTranslation.enabled')]
    public bool $enabled = false;

    #[Flow\InjectConfiguration(path:'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    public function __construct(
        protected readonly ContentRepositoryRegistry $contentRepositoryRegistry,
        protected readonly TranslatablePropertyNamesFactory $translatablePropertyNamesFactory,
        protected readonly TranslationServiceInterface $translationService,
    ) {
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        return new TranslationCommandHook(
            $this->enabled,
            $commandHooksFactoryDependencies->contentGraphReadModel,
            $commandHooksFactoryDependencies->nodeTypeManager,
            $this->translatablePropertyNamesFactory,
            $this->translationService,
            new ContentDimensionId($this->languageDimensionName)
        );
    }
}
