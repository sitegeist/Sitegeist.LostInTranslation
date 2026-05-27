<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * What a publication synchronisation rule does with a visited node whose target variant
 * **already exists**.
 *
 *  - {@see self::KeepExisting}: leave the existing variant alone (protect manual edits).
 *  - {@see self::ForceRefresh}: re-translate it from the current source (overwrite).
 *
 * Override: a present `StaleTranslation` always forces a refresh regardless of this setting — a
 * stale record means the source changed, so the existing translation is known to be outdated.
 * Therefore this only changes behaviour for existing-and-non-stale variants, and only under
 * {@see SynchronizationStrategy::Full} (in `Stale` mode every visited node is stale by definition).
 */
enum TranslationStrategy: string
{
    case KeepExisting = 'keep-existing';
    case ForceRefresh = 'force-refresh';
}
