<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Controller;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Mvc\Controller\ActionController;
use Sitegeist\LostInTranslation\Domain\SynchronizationMode;
use Sitegeist\LostInTranslation\Domain\SynchronizationRule;
use Sitegeist\LostInTranslation\Domain\SynchronizationRules;
use Sitegeist\LostInTranslation\Domain\SynchronizationStatusProvider;
use Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer;

/**
 * HTTP entry point for the Neos UI post-publish "sync now" prompt (see the JavaScript plugin).
 *
 * Both endpoints act on the `ask`-mode synchronization rules whose `sourceWorkspaceName` matches the workspace that was
 * just published — `auto` rules already synchronized inline during the publish, so they are intentionally ignored here.
 *
 *  - `pending` reports how many translations are out of sync for those rules (so the UI can decide whether to prompt).
 *  - `synchronize` runs {@see WorkspaceSynchronizer::synchronizeWorkspace()} per matching rule and returns the totals.
 *
 * The manual, mode-agnostic "sync now" surface lives in the backend module
 * ({@see LostInTranslationModuleController}); this controller is purely the publish-driven UI prompt.
 */
class WorkspaceSynchronizationController extends ActionController
{
    #[Flow\Inject]
    protected SynchronizationStatusProvider $synchronizationStatusProvider;

    #[Flow\Inject]
    protected WorkspaceSynchronizer $workspaceSynchronizer;

    /**
     * @var array<int,array<string,string>>
     */
    #[Flow\InjectConfiguration(path: 'nodeTranslation.synchronization')]
    protected array $synchronization = [];

    /**
     * Report the number of out-of-sync translations for the `ask` rules of the just-published workspace.
     */
    public function pendingAction(string $workspaceName, string $contentRepositoryId = 'default'): string
    {
        $contentRepositoryIdObject = ContentRepositoryId::fromString($contentRepositoryId);
        $rules = $this->askRulesForPublicationTarget(WorkspaceName::fromString($workspaceName));

        $perRule = [];
        $pendingCount = 0;
        foreach ($rules as $rule) {
            $count = $this->synchronizationStatusProvider->pendingCountForRule($contentRepositoryIdObject, $rule);
            $pendingCount += $count;
            $perRule[] = [
                'targetWorkspaceName' => $rule->targetWorkspaceName,
                'targetDimension' => $rule->targetDimension,
                'count' => $count,
            ];
        }

        return $this->jsonResponse([
            'pendingCount' => $pendingCount,
            'perRule' => $perRule,
        ]);
    }

    /**
     * Run the deferred synchronization for the `ask` rules of the just-published workspace.
     */
    public function synchronizeAction(string $workspaceName, string $contentRepositoryId = 'default'): string
    {
        $contentRepositoryIdObject = ContentRepositoryId::fromString($contentRepositoryId);
        $rules = $this->askRulesForPublicationTarget(WorkspaceName::fromString($workspaceName));

        $stalePropertyCommandsDispatched = 0;
        $variantCommandsDispatched = 0;
        $skippedNodes = 0;
        // A rule may short-circuit entirely (e.g. its target workspace does not exist or is not based on the source).
        // Collect those reasons so the UI can raise an error notification instead of silently reporting "0 synced".
        $errors = [];
        foreach ($rules as $rule) {
            $result = $this->workspaceSynchronizer->synchronizeRule($contentRepositoryIdObject, $rule);
            if ($result->skippedReason !== null) {
                $errors[] = sprintf('%s → %s: %s', $rule->sourceWorkspaceName, $rule->targetWorkspaceName, $result->skippedReason);
                continue;
            }
            $stalePropertyCommandsDispatched += $result->totalStalePropertyCommandsDispatched();
            $variantCommandsDispatched += $result->totalVariantCommandsDispatched();
            $skippedNodes += $result->totalSkippedNodes();
        }

        return $this->jsonResponse([
            'stalePropertyCommandsDispatched' => $stalePropertyCommandsDispatched,
            'variantCommandsDispatched' => $variantCommandsDispatched,
            'skippedNodes' => $skippedNodes,
            'errors' => $errors,
        ]);
    }

    /**
     * @return list<SynchronizationRule>
     */
    private function askRulesForPublicationTarget(WorkspaceName $publicationTarget): array
    {
        return array_values(array_filter(
            SynchronizationRules::fromArray($this->synchronization)->forPublicationTarget($publicationTarget)->items,
            static fn (SynchronizationRule $rule): bool => $rule->mode === SynchronizationMode::Ask,
        ));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function jsonResponse(array $data): string
    {
        $this->response->setContentType('application/json');
        return \json_encode($data, JSON_THROW_ON_ERROR);
    }
}
