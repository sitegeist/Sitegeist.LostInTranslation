<?php

declare(strict_types=1);

namespace Sitegeist\LostInTranslation\ContentRepository\CommandHook;

use Neos\ContentRepository\Core\CommandHandler\CommandHookInterface;
use Neos\ContentRepository\Core\CommandHandler\CommandInterface;
use Neos\ContentRepository\Core\CommandHandler\Commands;
use Neos\ContentRepository\Core\EventStore\PublishedEvents;

/**
 * A hook that does nothing, returned by this package's hook factories when the feature is switched off AND the
 * configured language dimension does not exist in the content repository.
 *
 * Both real hooks need a {@see \Neos\ContentRepository\Core\Dimension\ContentDimension} to be constructible at all, so
 * "off" cannot simply be expressed by passing `enabled: false` in that case — hence a separate no-op rather than a
 * nullable dimension threaded through the hooks.
 *
 * This exists so that a content repository WITHOUT the language dimension stays usable once an operator has explicitly
 * disabled the package: its hooks are registered on the `default` preset for every CR using that preset, and a throwing
 * factory makes the CR unbuildable, not merely untranslated. With the feature ON, a missing language dimension remains
 * a hard failure — that is a genuine misconfiguration and silence would be worse.
 */
final class DisabledCommandHook implements CommandHookInterface
{
    public function onBeforeHandle(CommandInterface $command): CommandInterface
    {
        return $command;
    }

    public function onAfterHandle(CommandInterface $command, PublishedEvents $events): Commands
    {
        return Commands::createEmpty();
    }
}
