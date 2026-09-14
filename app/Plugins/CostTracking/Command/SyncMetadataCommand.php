<?php

namespace Leantime\Plugins\CostTracking\Command;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Refreshes the installed plugin record in zp_plugins from this plugin's
 * composer.json.
 *
 * Plugin metadata (name, description, version, homepage, authors) is only read
 * from composer.json at install/discovery time and then persisted in the
 * database. Changing composer.json on an already-installed instance therefore
 * does not update what the plugin list displays. Run this command after
 * deploying new metadata to sync the database row without uninstalling
 * (which would drop the zp_ticket_costs data).
 *
 * Usage:
 *   php bin/leantime plugin:costtracking:sync-metadata
 *   php bin/leantime plugin:costtracking:sync-metadata --dry-run
 */
#[AsCommand(
    name: 'plugin:costtracking:sync-metadata',
    description: 'Refresh the installed CostTracking plugin record (name, description, version, homepage, authors) from composer.json',
)]
class SyncMetadataCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected function configure(): void
    {
        parent::configure();

        $this->addOption(
            'foldername',
            null,
            InputOption::VALUE_REQUIRED,
            'The plugin folder name as stored in zp_plugins.foldername',
            basename(dirname(__DIR__))
        )->addOption(
            'dry-run',
            null,
            InputOption::VALUE_NONE,
            'Preview the changes without writing them to the database'
        );
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        ! defined('BASE_URL') && define('BASE_URL', '');
        ! defined('CURRENT_URL') && define('CURRENT_URL', '');

        $io = new SymfonyStyle($input, $output);
        $foldername = (string) $input->getOption('foldername');
        $dryRun = (bool) $input->getOption('dry-run');

        $composerPath = dirname(__DIR__).'/composer.json';

        if (! is_file($composerPath)) {
            $io->error('composer.json not found at '.$composerPath);

            return Command::FAILURE;
        }

        $meta = json_decode((string) file_get_contents($composerPath), true);

        if (! is_array($meta)) {
            $io->error('composer.json is not valid JSON');

            return Command::FAILURE;
        }

        $row = DB::table('zp_plugins')->where('foldername', $foldername)->first();

        if ($row === null) {
            $io->warning("No installed plugin row found with foldername '{$foldername}'. Nothing to update.");

            return Command::SUCCESS;
        }

        $desired = [
            'name' => (string) ($meta['name'] ?? ''),
            'description' => (string) ($meta['description'] ?? ''),
            'version' => (string) ($meta['version'] ?? ''),
            'homepage' => (string) ($meta['homepage'] ?? ''),
            'authors' => json_encode($meta['authors'] ?? []),
        ];

        $rows = [];
        $update = [];

        foreach ($desired as $column => $value) {
            $current = (string) ($row->{$column} ?? '');

            if ($current !== $value) {
                $update[$column] = $value;
                $rows[] = [$column, $current, $value];
            }
        }

        if ($update === []) {
            $io->success('Plugin metadata is already up to date. Nothing to do.');

            return Command::SUCCESS;
        }

        $io->table(['Column', 'From', 'To'], $rows);

        if ($dryRun) {
            $io->note('Dry run: no changes were written.');

            return Command::SUCCESS;
        }

        DB::table('zp_plugins')->where('id', $row->id)->update($update);

        // Bust the caches the plugin service uses so the UI reflects the new
        // metadata immediately.
        Cache::store('installation')->forget('plugins.enabledPlugins');
        Cache::store('installation')->forget('plugins.marketplacePluginsFlat');

        $io->success("Updated metadata for '{$foldername}' (plugin id {$row->id}).");

        return Command::SUCCESS;
    }
}
