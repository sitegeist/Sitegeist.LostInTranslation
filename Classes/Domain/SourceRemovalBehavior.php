<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * What an automatic synchronization rule does to the target dimension when a node is removed in the source language.
 *
 *  - {@see self::KeepTarget}: leave the translated variant in place. The target dimension keeps the node even though it
 *    no longer exists in the source — source and target are allowed to diverge on deletions. This is the default so
 *    existing rule configurations keep behaving exactly as before.
 *  - {@see self::RemoveTarget}: mirror the deletion — remove the corresponding node in the target dimension. Gated by
 *    the rule's {@see SynchronizationScope}: under {@see SynchronizationScope::Content} only content nodes are removed
 *    (never Documents, symmetric with "Content scope never auto-creates Documents"), under
 *    {@see SynchronizationScope::Document} Documents are removed too. Removing the subtree root is enough — the Content
 *    Repository cascades descendant removal in the target dimension.
 *
 * For {@see SynchronizationMode::Auto} rules the removal is mirrored inline on publish (incrementally, from the
 * publish's own removal events); for {@see SynchronizationMode::Ask} rules and the manual CLI it is reconciled on the
 * deliberate "sync now" run (by diffing the target dimension against the source).
 */
enum SourceRemovalBehavior: string
{
    case KeepTarget = 'keep-target';
    case RemoveTarget = 'remove-target';
}
