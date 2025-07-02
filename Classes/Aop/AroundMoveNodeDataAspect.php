<?php

namespace Sitegeist\LostInTranslation\Aop;

use Neos\ContentRepository\Domain\Model\NodeData;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Aop\JoinPointInterface;
use Neos\Utility\Arrays;
use Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService;

/**
 * @Flow\Aspect
 */
class AroundMoveNodeDataAspect
{
    /**
     * @Flow\Inject
     * @var NodeTranslationService
     */
    protected $nodeTranslationService;

    /**
     * @Flow\InjectConfiguration(path="nodeTranslation.languageDimensionName")
     * @var string
     */
    protected $languageDimensionName;

    /**
     * @Flow\InjectConfiguration(package="Neos.ContentRepository", path="contentDimensions")
     * @var array<string, array{}>
     */
    protected $contentDimensionConfiguration;

    /**
     * @param  JoinPointInterface  $joinPoint
     * @return mixed
     *
     * @Flow\Around("method(Neos\ContentRepository\Domain\Model\Node->moveNodeData())")
     */
    public function aroundMoveNodeData(JoinPointInterface $joinPoint): mixed
    {
        // @phpstan-ignore constant.notFound
        if (FLOW_SAPITYPE === 'CLI') {
            return $joinPoint->getAdviceChain()->proceed($joinPoint);
        }

        /** @var NodeData $nodeData */
        $nodeData = $joinPoint->getMethodArgument('nodeData');
        $configuration = $this->contentDimensionConfiguration[$this->languageDimensionName];

        $dimensionValues = $nodeData->getDimensionValues();
        $nodeDataLanguageDimensionValue = Arrays::getValueByPath($dimensionValues, $this->languageDimensionName . '.0');

        if (
            !is_null($nodeDataLanguageDimensionValue) && !$this->nodeTranslationService->isRecursionPreventionEnabled() && Arrays::getValueByPath(
                $configuration,
                sprintf('presets.%s.options.translationStrategy', $nodeDataLanguageDimensionValue)
            ) === NodeTranslationService::TRANSLATION_STRATEGY_SYNC
        ) {
            return null;
        }

        return $joinPoint->getAdviceChain()->proceed($joinPoint);
    }
}
