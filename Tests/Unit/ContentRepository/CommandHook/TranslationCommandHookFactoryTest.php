<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Tests\Unit\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\Dimension\ConfigurationBasedContentDimensionSource;
use Neos\ContentRepository\Core\DimensionSpace\ContentDimensionZookeeper;
use Neos\ContentRepository\Core\DimensionSpace\InterDimensionalVariationGraph;
use Neos\ContentRepository\Core\Factory\CommandHooksFactoryDependencies;
use Neos\ContentRepository\Core\NodeType\NodeTypeManager;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentGraphReadModelInterface;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Tests\UnitTestCase;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\CommandHook\DisabledCommandHook;
use Sitegeist\LostInTranslation\ContentRepository\CommandHook\TranslationCommandHookFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\TranslationServiceInterface;

/**
 * This factory is registered FIRST in the `default` preset, so it is the one that decides what happens to a content
 * repository without the configured language dimension — see {@see SynchronizationCommandHookFactoryTest}.
 */
class TranslationCommandHookFactoryTest extends UnitTestCase
{
    private function factory(bool $enabled): TranslationCommandHookFactory
    {
        $factory = new TranslationCommandHookFactory(
            (new \ReflectionClass(ContentRepositoryRegistry::class))->newInstanceWithoutConstructor(),
            new NodeTypeTranslationDirectiveFactory(),
            $this->createMock(TranslationServiceInterface::class),
            new AISystemTranslationRuntimeState(),
        );
        $factory->enabled = $enabled;
        $factory->languageDimensionName = 'language';
        $factory->experimentalApplyHtmlEntityDecodeAfterTranslation = false;
        return $factory;
    }

    private function dependenciesWithoutLanguageDimension(): CommandHooksFactoryDependencies
    {
        $contentDimensionSource = new ConfigurationBasedContentDimensionSource([]);
        return CommandHooksFactoryDependencies::create(
            ContentRepositoryId::fromString('default'),
            $this->createMock(ContentGraphReadModelInterface::class),
            NodeTypeManager::createFromArrayConfiguration([]),
            $contentDimensionSource,
            new InterDimensionalVariationGraph(
                $contentDimensionSource,
                new ContentDimensionZookeeper($contentDimensionSource),
            ),
        );
    }

    /** @test */
    public function buildReturnsADisabledHookWhenTranslationIsOffAndTheLanguageDimensionIsMissing(): void
    {
        $hook = $this->factory(false)->build($this->dependenciesWithoutLanguageDimension());

        self::assertInstanceOf(DisabledCommandHook::class, $hook);
    }

    /** @test */
    public function buildThrowsWhenTranslationIsOnButTheLanguageDimensionIsMissing(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Language dimension language was not found in content repository default');

        $this->factory(true)->build($this->dependenciesWithoutLanguageDimension());
    }
}
