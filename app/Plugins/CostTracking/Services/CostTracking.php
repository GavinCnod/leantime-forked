<?php

namespace Leantime\Plugins\CostTracking\Services;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Leantime\Domain\Plugins\Contracts\PluginInterface;

/**
 * CostTracking plugin lifecycle service.
 *
 * The plugin keeps its data in a dedicated table (zp_ticket_costs) and never
 * alters core tables, so install/uninstall only manages that one table.
 */
class CostTracking implements PluginInterface
{
    public const TABLE = 'zp_ticket_costs';

    /**
     * Creates the plugin data table. Invoked by `plugin:install` and the
     * marketplace install flow.
     */
    public function install(): bool
    {
        try {
            if (Schema::hasTable(self::TABLE)) {
                return true;
            }

            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->integer('ticketId')->primary();
                $table->decimal('cost', 12, 2)->nullable();
                $table->decimal('actualCost', 12, 2)->nullable();
                $table->dateTime('modified')->nullable();
            });

            return true;
        } catch (\Throwable $e) {
            Log::error('[CostTracking] install failed: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Drops the plugin data table. All cost data is removed with the plugin.
     */
    public function uninstall(): bool
    {
        try {
            Schema::dropIfExists(self::TABLE);

            return true;
        } catch (\Throwable $e) {
            Log::error('[CostTracking] uninstall failed: '.$e->getMessage());

            return false;
        }
    }

    public function enable(): bool
    {
        return true;
    }

    public function disable(): bool
    {
        return true;
    }
}
