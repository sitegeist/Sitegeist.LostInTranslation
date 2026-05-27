<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\Domain;

/**
 * Which nodes a publication synchronization rule visits.
 *
 *  - {@see self::Stale}: only nodes the {@see \Sitegeist\LostInTranslation\ContentRepository\StaleTranslationProjection\StaleTranslationProjection}
 *    flagged (a source property changed, or a variant was ported into the target dimension). Cheap;
 *    the right default for steady-state editing.
 *  - {@see self::Full}: walk the whole target subtree from the root and consider every translatable
 *    node, regardless of stale state. For rebuilding a target dimension or recovering after the
 *    projection drifted.
 */
enum SynchronizationStrategy: string
{
    case Stale = 'stale';
    case Full = 'full';
}
