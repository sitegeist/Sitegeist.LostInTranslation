<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * What an automatic synchronization rule does to the target dimension when a subtree tag (e.g. the `disabled` /
 * hide-show tag) is added to or removed from a node in the source language.
 *
 *  - {@see self::KeepTarget}: leave the target's tags untouched. The target dimension manages its own visibility — a
 *    reviewer can hide/show translated nodes independently of the source. This is the default so existing rule
 *    configurations keep behaving exactly as before.
 *  - {@see self::SyncToTarget}: mirror the source tag change — apply (`TagSubtree`) or remove (`UntagSubtree`) the same
 *    {@see \Neos\ContentRepository\Core\Feature\SubtreeTagging\Dto\SubtreeTag} on the corresponding node in the target
 *    dimension. Applies to any node type (Documents and Content alike) and is idempotent — a tag already matching the
 *    target's explicit state is skipped. Only mirrored for {@see SynchronizationMode::Auto} rules, inline on publish
 *    from the publish's own `SubtreeWasTagged` / `SubtreeWasUntagged` events.
 */
enum SourceTaggingBehavior: string
{
    case KeepTarget = 'keep-target';
    case SyncToTarget = 'sync-to-target';
}
