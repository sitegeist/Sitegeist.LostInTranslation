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
 * **Invariant — the mode decides WHEN, never WHAT.** Both modes run the same stale-driven synchronization and must
 * converge on the same target state for one and the same source-side change. A driver-specific outcome — which nodes are
 * in scope, whether a tag is mirrored, whether a kept subtree is descended into — is a bug, not a mode difference.
 *
 * The single permitted difference is COVERAGE, not semantics: the publish-driven path only ever sees the delta of the
 * publish it reacts to, while the deliberate runs diff the whole target dimension against the source and therefore also
 * catch up on changes no publish ever carried (made while the rule was off, before the feature existed, or outside a
 * publish). That is a difference in what each driver can OBSERVE, not in what it does with what it observes.
 */
enum SynchronizationMode: string
{
    case Auto = 'auto';
    case Ask = 'ask';
}
