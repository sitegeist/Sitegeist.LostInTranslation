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
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
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
        protected readonly NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator,
    ) {
    }

    public function build(CommandHooksFactoryDependencies $commandHooksFactoryDependencies): CommandHookInterface
    {
        $languageDimension = $commandHooksFactoryDependencies->contentDimensionSource->getDimension(
            new ContentDimensionId($this->languageDimensionName)
        );

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
                $this->nodeUriPathSegmentGenerator,
            );
        } else {
            throw new \Exception(sprintf('Lamguage dimension %s was nou found in content repository %s', $this->languageDimensionName, $commandHooksFactoryDependencies->contentRepositoryId->value));
        }
    }
}
