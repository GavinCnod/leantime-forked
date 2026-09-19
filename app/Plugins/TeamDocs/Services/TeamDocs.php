<?php

declare(strict_types=1);

namespace Leantime\Plugins\TeamDocs\Services;

use Leantime\Domain\Plugins\Contracts\PluginInterface;

/**
 * TeamDocs plugin lifecycle service.
 *
 * The plugin owns no database tables: its only state is the Markdown files
 * packaged under Resources/docs. Install/enable/disable therefore need no
 * schema work. Uninstall leaves the packaged files in place, since they ship
 * with the image and would simply reappear on the next deploy.
 */
class TeamDocs implements PluginInterface
{
    /**
     * No setup required — the plugin does not own any database tables.
     */
    public function install(): bool
    {
        return true;
    }

    /**
     * No teardown required — the plugin owns no database tables.
     */
    public function uninstall(): bool
    {
        return true;
    }

    /**
     * No activation work required.
     */
    public function enable(): bool
    {
        return true;
    }

    /**
     * No deactivation work required.
     */
    public function disable(): bool
    {
        return true;
    }
}
