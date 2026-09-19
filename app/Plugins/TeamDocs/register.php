<?php

declare(strict_types=1);

use Leantime\Core\Events\EventDispatcher;

/**
 * TeamDocs plugin bootstrap.
 *
 * Adds the team's documentation links to the core Help dropdown. The core
 * template owns the dropdown markup; this plugin owns only its three items.
 */
EventDispatcher::add_event_listener('leantime.*.insideHelpMenu', static function (): void {
    echo app('view')->make('teamdocs::helpMenu')->render();
});
