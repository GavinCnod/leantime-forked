<?php

declare(strict_types=1);

use Leantime\Core\Events\EventDispatcher;

/**
 * TeamDocs plugin bootstrap.
 *
 * Adds the team's documentation links to the core Help dropdown and nothing
 * else: no stylesheet of its own, no left-hand menu, no CostTracking changes.
 * The core template owns the dropdown markup; this plugin owns only its items.
 *
 * The documents are styled by core's `markdown.css`, which ships in the main
 * bundle alongside the `MarkdownRenderer` that produces the markup.
 */
EventDispatcher::add_event_listener('leantime.*.insideHelpMenu', static function (): void {
    echo app('view')->make('teamdocs::helpMenu')->render();
});
