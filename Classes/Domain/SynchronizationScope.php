<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * Which nodes an automatic synchronization rule mirrors into the target dimension.
 *
 *  - {@see self::Content}: only nodes whose containing Document already exists in the target dimension + workspace.
 *    Documents are never created automatically — adopting a Document into the target dimension stays a deliberate,
 *    manual editor action. A stale Document that already exists in the target still has its own properties
 *    (re-)translated.
 *  - {@see self::Document}: mirror the whole Document and Content structure — create (and translate) missing Document
 *    variants as well as Content variants, reproducing the source subtree in the target dimension.
 *
 * Both scopes act only on records flagged by the
 * {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}:
 * automatic synchronization is always stale-driven. Walking the whole tree from the root is the separate, manual
 * `synchronize --full` CLI command.
 */
enum SynchronizationScope: string
{
    case Content = 'Content';
    case Document = 'Document';

    /**
     * Whether a node may be (mirrored-)removed from the target dimension under this scope — the deletion-side mirror of
     * which nodes the scope creates. {@see self::Document} mirrors the whole structure, so it removes Documents and
     * Content alike; {@see self::Content} never touches Documents (symmetric with never auto-creating them), so it only
     * removes non-Document nodes.
     *
     * `$isDocument` is whether the node in question is a `Neos.Neos:Document` (resolved by the caller, which holds the
     * NodeTypeManager).
     */
    public function mayRemoveNode(bool $isDocument): bool
    {
        return match ($this) {
            self::Document => true,
            self::Content => !$isDocument,
        };
    }
}
