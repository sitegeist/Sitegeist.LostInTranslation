<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

use Neos\ContentRepository\Core\SharedModel\ContentRepository\ContentRepositoryId;
use Neos\ContentRepository\Core\SharedModel\Workspace\WorkspaceName;
use Neos\Flow\Annotations as Flow;
use Neos\Neos\Domain\Model\WorkspaceDescription;
use Neos\Neos\Domain\Model\WorkspaceRole;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignment;
use Neos\Neos\Domain\Model\WorkspaceRoleAssignments;
use Neos\Neos\Domain\Model\WorkspaceTitle;
use Neos\Neos\Domain\Service\WorkspaceService;

/**
 * Single source of truth for what a synchronization target workspace looks like when it is auto-created on first sync.
 *
 * A configuration rule may target a workspace (e.g. `de-review`) that does not exist yet. Both {@see WorkspaceSynchronizer}
 * and {@see FullWorkspaceSynchronizer} create it lazily through this provisioner so the shape stays consistent: a
 * **shared** review workspace based on `live`. The base is always `live` (not the rule's source workspace) so the review
 * branches off published content. Editors get collaborator access via the `Neos.Neos:AbstractEditor` group; no manager is
 * assigned because synchronization may run headless (CLI / publish hook) without a current user — admins manage it like
 * any other shared workspace.
 */
class ReviewWorkspaceProvisioner
{
    #[Flow\Inject]
    protected WorkspaceService $workspaceService;

    public function createSharedReviewWorkspace(
        ContentRepositoryId $contentRepositoryId,
        WorkspaceName $targetWorkspaceName,
    ): void {
        $this->workspaceService->createSharedWorkspace(
            $contentRepositoryId,
            $targetWorkspaceName,
            WorkspaceTitle::fromString($targetWorkspaceName->value),
            WorkspaceDescription::fromString('Automatically created for translation synchronization.'),
            WorkspaceName::forLive(),
            WorkspaceRoleAssignments::create(
                WorkspaceRoleAssignment::createForGroup('Neos.Neos:AbstractEditor', WorkspaceRole::COLLABORATOR),
            ),
        );
    }
}
