<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * Whether an automatic synchronization rule may CREATE Document variants in the target dimension. That is the scope's
 * one and only job.
 *
 *  - {@see self::Content}: only act on nodes whose containing Document already exists in the target dimension +
 *    workspace. Documents are never created automatically — adopting a Document into the target dimension stays a
 *    deliberate, manual editor action. A Document that already exists in the target still has its own properties
 *    (re-)translated when stale.
 *  - {@see self::Document}: mirror the whole Document and Content structure — create (and translate) missing Document
 *    variants as well as Content variants, reproducing the source subtree in the target dimension.
 *
 * **Not** consulted for anything else. Subtree tags (incl. the `removed` soft-removal tag, i.e. deletions) and mirrored
 * hard removals are applied regardless of scope: a node hidden or deleted in the source language is hidden or deleted in
 * the target whether the rule mirrors structure or only content. The scope decides what synchronization *creates*, never
 * what it converges once a node exists in both dimensions.
 *
 * Both scopes act only on records flagged by the
 * {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}:
 * automatic synchronization is always stale-driven. Walking the whole tree from the root is the separate, manual
 * `synchronize --full` CLI command, which has no rule and therefore no scope (it always behaves as {@see self::Document}).
 */
enum SynchronizationScope: string
{
    case Content = 'Content';
    case Document = 'Document';
}
