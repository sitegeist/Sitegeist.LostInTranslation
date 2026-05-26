<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\Dimension\ContentDimensionId;
use Neos\ContentRepository\Core\DimensionSpace\DimensionSpacePoint;
use Neos\ContentRepository\Core\DimensionSpace\OriginDimensionSpacePoint;
use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\ContentRepositoryRegistry\ContentRepositoryRegistry;
use Neos\Flow\Annotations as Flow;
use Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationReadModel;

/**
 * Workspace-level orchestrator on top of {@see Retranslator}.
 *
 * Loads every stale-translation record matching the target (workspace, originDimensionSpacePoint)
 * and dispatches one {@see Retranslator::retranslateNode()} call per record. The dispatch chain
 * is idempotent: a parent's subtree walk that clears child stale records causes later iterations
 * to come back as `RetranslationResult::isNoOp()` rather than re-translating.
 *
 * Source workspace + source dimension are part of the public API in anticipation of future
 * cross-workspace synchronisation. For now the only supported shape is:
 *  - sourceWorkspace == targetWorkspace
 *  - sourceDimension equals the configured `referenceLanguage` of targetDimension
 *
 * Either mismatch short-circuits with {@see WorkspaceSynchronisationResult::skipped()} so the
 * caller (typically the `synchronise` CLI) can surface a clear error.
 */
class WorkspaceSynchroniser
{
    #[Flow\Inject]
    protected ContentRepositoryRegistry $contentRepositoryRegistry;

    #[Flow\Inject]
    protected Retranslator $retranslator;

    #[Flow\InjectConfiguration(path: 'nodeTranslation.languageDimensionName')]
    protected string $languageDimensionName;

    public function synchroniseWorkspace(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $sourceWorkspaceName,
        DimensionSpacePoint $sourceDimensionSpacePoint,
        WorkspaceName $targetWorkspaceName,
        DimensionSpacePoint $targetDimensionSpacePoint,
        bool $dryRun = false,
    ): WorkspaceSynchronisationResult {
        if (!$sourceWorkspaceName->equals($targetWorkspaceName)) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'cross-workspace synchronisation is not yet supported (source workspace "%s" != target workspace "%s")',
                $sourceWorkspaceName->value,
                $targetWorkspaceName->value,
            ));
        }

        $cr = $this->contentRepositoryRegistry->get($contentRepositoryId);
        $languageDimensionId = new ContentDimensionId($this->languageDimensionName);
        $resolver = new ReferenceDimensionSpacePointResolver(
            allowedDimensionSubspace: $cr->getVariationGraph()->getDimensionSpacePoints(),
            contentDimensionSource: $cr->getContentDimensionSource(),
            languageDimensionId: $languageDimensionId,
        );
        $expectedSourceDsp = $resolver->tryResolveSourceDimensionSpacePoint($targetDimensionSpacePoint);
        if ($expectedSourceDsp === null) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'no referenceLanguage configured for target dimension %s',
                $targetDimensionSpacePoint->toJson(),
            ));
        }
        if (!$expectedSourceDsp->equals($sourceDimensionSpacePoint)) {
            return WorkspaceSynchronisationResult::skipped(sprintf(
                'source dimension %s does not match configured referenceLanguage %s for target dimension %s',
                $sourceDimensionSpacePoint->toJson(),
                $expectedSourceDsp->toJson(),
                $targetDimensionSpacePoint->toJson(),
            ));
        }

        $targetOrigin = OriginDimensionSpacePoint::fromDimensionSpacePoint($targetDimensionSpacePoint);
        $finder = $cr->projectionState(StaleTranslationReadModel::class)->staleTranslationFinder;

        $perNodeResults = [];
        foreach ($finder->findAll() as $entry) {
            if (!$entry->workspaceName->equals($targetWorkspaceName)) {
                continue;
            }
            if ($entry->originDimensionSpacePoint->hash !== $targetOrigin->hash) {
                continue;
            }
            if ($dryRun) {
                $perNodeResults[] = new PerNodeSynchronisationResult(
                    $entry->nodeAggregateId,
                    RetranslationResult::skipped('dry-run'),
                );
                continue;
            }
            $result = $this->retranslator->retranslateNode(
                contentRepositoryId: $contentRepositoryId,
                workspaceName: $targetWorkspaceName,
                nodeAggregateId: $entry->nodeAggregateId,
                targetDimensionSpacePoint: $targetDimensionSpacePoint,
            );
            $perNodeResults[] = new PerNodeSynchronisationResult($entry->nodeAggregateId, $result);
        }

        return new WorkspaceSynchronisationResult($perNodeResults);
    }
}
