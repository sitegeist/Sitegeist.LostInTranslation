<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * When an automatic synchronization rule mirrors its changes into the target dimension.
 *
 *  - {@see self::Auto}: synchronize inline during the publish that lands on the rule's `sourceWorkspaceName`. The
 *    {@see \Sitegeist\LostInTranslation\ContentRepository\CommandHook\SynchronizationCommandHook} emits the translation
 *    commands as part of the publish command pipeline — no editor action required.
 *  - {@see self::Ask}: the command hook does NOT synchronize this rule on publish. Instead the Neos UI prompts the
 *    editor after a successful publish (and the backend module lists the rule), so the synchronization runs in its own
 *    request via {@see \Sitegeist\LostInTranslation\Domain\WorkspaceSynchronizer} rather than blocking the publish.
 *
 * Either way the synchronization itself is the same stale-driven run; the mode only decides whether it is triggered
 * automatically as part of the publish or deferred to a deliberate "sync now" action.
 */
enum SynchronizationMode: string
{
    case Auto = 'auto';
    case Ask = 'ask';
}
