<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\ContentRepository;
use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\Feature\NodeModification\Command\SetNodeProperties;
use Neos\ContentRepository\Core\Feature\NodeModification\Dto\PropertyValuesToWrite;
use Neos\ContentRepository\Core\Feature\NodeVariation\Command\CreateNodeVariant;
use Neos\ContentRepository\Core\Projection\ContentGraph\ContentSubgraphInterface;
use Neos\ContentRepository\Core\Projection\ContentGraph\Filter\FindSubtreeFilter;
use Neos\ContentRepository\Core\Projection\ContentGraph\Node;
use Neos\ContentRepository\Core\Projection\ContentGraph\Subtree;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Node\NodeAggregateId;
use Neos\ContentRepository\Core\SharedModel\Node\PropertyNames;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\SubtreeTagging\NeosVisibilityConstraints;
use Neos\Neos\Utility\NodeUriPathSegmentGenerator;
use Psr\Log\LoggerInterface;
use Sitegeist\LostInTranslation\ContentRepository\AuthProvider\AISystemTranslationRuntimeState;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationFinder;
use Sitegeist\LostInTranslation\Domain\Directive\DimensionValueDirectiveFactory;
use Sitegeist\LostInTranslation\Domain\Directive\NodeTypeTranslationDirectiveFactory;
use Sitegeist\LostInTranslation\Utility\ArrayFlatteningUtility;

class Retranslator
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected TranslationServiceInterface $translationService;

    #[Flow\Inject]
    protected NodeTypeTranslationDirectiveFactory $nodeTypeTranslationDirectiveFactory;

    #[Flow\Inject]
    protected AISystemTranslationRuntimeState $aiSystemTranslationRuntimeState;

    #[Flow\Inject]
    protected NodeUriPathSegmentGenerator $nodeUriPathSegmentGenerator;

    protected ?LoggerInterface $logger = null;

    public function injectLogger(LoggerInterface $logger): void
    {
        $this->logger = $logger;
    }

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    public string $languageDimensionName;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.experimental-applyHtmlEntityDecodeAfterTranslation')]
    public bool $experimentalApplyHtmlEntityDecodeAfterTranslation = false;

    public function retranslateNode(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        DimensionSpacePoint $targetDimensionSpacePoint,
    ): void {
        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);

        $languageDimension = $cr->getContentDimensionSource()->getDimension($languageDimensionId);
        if ($languageDimension === null) {
            $this->logger?->debug(sprintf(
                'Retranslator: language dimension "%s" not configured in CR "%s"; skipping.',
                $this->languageDimensionName,
                $contentRepositoryId->value,
            ));
            return;
        }

        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $sourceDimensionSpacePoint = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($sourceDimensionSpacePoint === null) {
            $this->logger?->debug(sprintf(
                'Retranslator: no referenceLanguage configured for target DSP %s; skipping.',
                $targetDimensionSpacePoint->toJson(),
            ));
            return;
        }

        $dimensionValueDirectiveFactory = new DimensionValueDirectiveFactory();
        $sourceDirective = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($sourceDimensionSpacePoint),
        );
        $targetDirective = $dimensionValueDirectiveFactory->tryCreateForDimensionAndOriginDimensionSpacePoint(
            $languageDimension,
            OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
        );
        $sourceDeeplLanguage = $sourceDirective?->deeplSourceId;
        $targetDeeplLanguage = $targetDirective?->deeplTargetId;
        if ($sourceDeeplLanguage === null || $targetDeeplLanguage === null) {
            $this->logger?->debug(sprintf(
                'Retranslator: DeepL language not resolvable for source %s or target %s; skipping.',
                $sourceDimensionSpacePoint->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
            return;
        }

        $contentGraph = $cr->getContentGraph($workspaceName);
        $sourceSubgraph = $contentGraph->getSubgraph($sourceDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());
        $targetSubgraph = $contentGraph->getSubgraph($targetDimensionSpacePoint, NeosVisibilityConstraints::excludeRemoved());

        $sourceSubtree = $sourceSubgraph->findSubtree($nodeAggregateId, FindSubtreeFilter::create());
        if ($sourceSubtree === null) {
            $this->logger?->debug(sprintf(
                'Retranslator: source node %s not found in DSP %s; skipping.',
                $nodeAggregateId->value,
                $sourceDimensionSpacePoint->toJson(),
            ));
            return;
        }

        /** @var list<SetNodeProperties> $stalePropertyCommands */
        $stalePropertyCommands = $this->collectStalePropertyCommands(
            cr: $cr,
            workspaceName: $workspaceName,
            nodeAggregateId: $nodeAggregateId,
            sourceSubgraph: $sourceSubgraph,
            targetSubgraph: $targetSubgraph,
            sourceDeeplLanguage: $sourceDeeplLanguage,
            targetDeeplLanguage: $targetDeeplLanguage,
        );

        /** @var list<CreateNodeVariant> $variantCommands */
        $variantCommands = [];
        $this->collectMissingVariantCommands(
            subtree: $sourceSubtree,
            targetSubgraph: $targetSubgraph,
            workspaceName: $workspaceName,
            targetDimensionSpacePoint: $targetDimensionSpacePoint,
            commands: $variantCommands,
        );

        foreach ($stalePropertyCommands as $command) {
            $this->dispatchAsAi($cr, $command);
        }
        foreach ($variantCommands as $command) {
            $cr->handle($command);
        }
    }

    /**
     * @return list<SetNodeProperties>
     */
    private function collectStalePropertyCommands(
        ContentRepository $cr,
        WorkspaceName $workspaceName,
        NodeAggregateId $nodeAggregateId,
        ContentSubgraphInterface $sourceSubgraph,
        ContentSubgraphInterface $targetSubgraph,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): array {
        if ($targetSubgraph->findNodeById($nodeAggregateId) === null) {
            return [];
        }
        $targetSubtree = $targetSubgraph->findSubtree($nodeAggregateId, FindSubtreeFilter::create());
        if ($targetSubtree === null) {
            return [];
        }

        $finder = $cr->projectionState(StaleTranslationFinder::class);
        $staleTranslations = $finder->findBySubtree($targetSubtree);

        $commands = [];
        foreach ($staleTranslations as $staleTranslation) {
            $sourceNode = $sourceSubgraph->findNodeById($staleTranslation->nodeAggregateId);
            if ($sourceNode === null) {
                $this->logger?->debug(sprintf(
                    'Retranslator: stale node %s missing in source DSP; skipping.',
                    $staleTranslation->nodeAggregateId->value,
                ));
                continue;
            }
            $command = $this->tryBuildSetNodeProperties(
                cr: $cr,
                sourceNode: $sourceNode,
                stalePropertyNames: $staleTranslation->propertyNames,
                workspaceName: $workspaceName,
                targetOrigin: $staleTranslation->originDimensionSpacePoint,
                sourceDeeplLanguage: $sourceDeeplLanguage,
                targetDeeplLanguage: $targetDeeplLanguage,
            );
            if ($command !== null) {
                $commands[] = $command;
            }
        }
        return $commands;
    }

    /**
     * @param list<CreateNodeVariant> $commands
     */
    private function collectMissingVariantCommands(
        Subtree $subtree,
        ContentSubgraphInterface $targetSubgraph,
        WorkspaceName $workspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        array &$commands,
    ): void {
        $sourceNode = $subtree->node;
        if ($targetSubgraph->findNodeById($sourceNode->aggregateId) === null) {
            $commands[] = CreateNodeVariant::create(
                $workspaceName,
                $sourceNode->aggregateId,
                $sourceNode->originDimensionSpacePoint,
                OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint),
            );
        }
        foreach ($subtree->children as $childSubtree) {
            $this->collectMissingVariantCommands(
                $childSubtree,
                $targetSubgraph,
                $workspaceName,
                $targetDimensionSpacePoint,
                $commands,
            );
        }
    }

    private function tryBuildSetNodeProperties(
        ContentRepository $cr,
        Node $sourceNode,
        PropertyNames $stalePropertyNames,
        WorkspaceName $workspaceName,
        OriginDimensionSpacePoint $targetOrigin,
        string $sourceDeeplLanguage,
        string $targetDeeplLanguage,
    ): ?SetNodeProperties {
        $nodeType = $cr->getNodeTypeManager()->getNodeType($sourceNode->nodeTypeName);
        if ($nodeType === null) {
            return null;
        }
        $directive = $this->nodeTypeTranslationDirectiveFactory->createForNodeType($nodeType);
        if ($directive->enabled === false) {
            return null;
        }

        /** @var array<non-empty-string, string|array<non-empty-string, string>> $propertiesToTranslate */
        $propertiesToTranslate = [];
        foreach ($stalePropertyNames as $propertyName) {
            $translatable = $directive->translatablePropertyNames->findByName($propertyName);
            if ($translatable === null) {
                continue;
            }
            if (!$sourceNode->hasProperty($propertyName)) {
                continue;
            }
            $sourceValue = $sourceNode->getProperty($propertyName);
            if ($sourceValue === null || (is_string($sourceValue) && trim($sourceValue) === '')) {
                continue;
            }
            $name = $propertyName->value;
            assert($name !== '');
            if (is_object($sourceValue) && ($connector = $translatable->translationConnector) !== null) {
                $propertiesToTranslate[$name] = $connector->extractTranslations($sourceValue);
            } elseif (is_string($sourceValue)) {
                $propertiesToTranslate[$name] = $sourceValue;
            }
        }

        if ($propertiesToTranslate === []) {
            return null;
        }

        $deflated = ArrayFlatteningUtility::deflate($propertiesToTranslate);
        /** @var array<non-empty-string, string> $translatedDeflated */
        $translatedDeflated = $this->translationService->translate(
            $deflated,
            $targetDeeplLanguage,
            $sourceDeeplLanguage,
        );
        if ($this->experimentalApplyHtmlEntityDecodeAfterTranslation) {
            $translatedDeflated = array_map(
                static fn (string $value): string => html_entity_decode($value),
                $translatedDeflated,
            );
        }
        $translatedProperties = ArrayFlatteningUtility::enflate($translatedDeflated);

        $propertiesToSet = [];
        foreach ($translatedProperties as $name => $translatedValue) {
            if (
                $name === 'uriPathSegment'
                && is_string($translatedValue)
                && !preg_match('/^[a-z0-9\-]+$/i', $translatedValue)
            ) {
                $translatedValue = $this->nodeUriPathSegmentGenerator->generateUriPathSegment(null, $translatedValue);
            }
            $targetValue = null;
            if (is_array($translatedValue)) {
                $translatable = $directive->translatablePropertyNames->findByName($name);
                $connector = $translatable?->translationConnector;
                if ($connector !== null) {
                    $sourceValue = $sourceNode->getProperty($name);
                    if (is_object($sourceValue)) {
                        $targetValue = $connector->applyTranslations($sourceValue, $translatedValue);
                    }
                }
            } else {
                $targetValue = $translatedValue;
            }
            if ($targetValue !== null) {
                $propertiesToSet[$name] = $targetValue;
            }
        }

        if ($propertiesToSet === []) {
            return null;
        }

        return SetNodeProperties::create(
            workspaceName: $workspaceName,
            nodeAggregateId: $sourceNode->aggregateId,
            originDimensionSpacePoint: $targetOrigin,
            propertyValues: PropertyValuesToWrite::fromArray($propertiesToSet),
        );
    }

    private function dispatchAsAi(ContentRepository $cr, CommandInterface $command): void
    {
        $this->aiSystemTranslationRuntimeState->setActiveAIServiceId($this->translationService->getAIServiceId());
        try {
            $cr->handle($command);
        } finally {
            $this->aiSystemTranslationRuntimeState->resetActiveAIServiceId();
        }
    }
}
