<?php

namespace App\Console\Commands;

use App\Support\CatalogTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Loads an `orlena:export-catalog` file into a new server, keeping IDs. Only into a shop without orders:
 * the catalog tables must be empty, or --replace clears them first (e.g. after `migrate --seed`).
 */
class ImportCatalog extends Command
{
    protected $signature = 'orlena:import-catalog {path=storage/app/private/catalog-export.json} {--replace : Clear the catalog tables first (only while there are no orders)}';

    protected $description = 'Import the catalog, content and settings exported from another server';

    public function handle(): int
    {
        $path = base_path($this->argument('path'));
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (! is_array($data) || ($data['version'] ?? null) !== CatalogTransfer::VERSION || ! is_array($data['tables'] ?? null)) {
            $this->error('Not a catalog export file: '.$path);

            return self::FAILURE;
        }
        if (DB::table('orders')->exists()) {
            $this->error('This shop already has orders. The import is only for a new server.');

            return self::FAILURE;
        }
        $filled = array_filter(CatalogTransfer::TABLES, fn ($table) => DB::table($table)->exists());
        if ($filled && ! $this->option('replace')) {
            $this->error('Not empty: '.implode(', ', $filled).'. Run with --replace to clear them first.');

            return self::FAILURE;
        }
        $latest = DB::table('migrations')->max('migration');
        if (($data['migration'] ?? '') > $latest) {
            $this->error('The export comes from a newer version ('.$data['migration'].'). Deploy and migrate first.');

            return self::FAILURE;
        }

        Schema::withoutForeignKeyConstraints(function () use ($data) {
            DB::transaction(function () use ($data) {
                foreach (array_reverse(CatalogTransfer::TABLES) as $table) {
                    DB::table($table)->delete();
                }
                foreach (CatalogTransfer::TABLES as $table) {
                    $columns = Schema::getColumnListing($table);
                    // Columns added after the export keep their defaults; columns that no longer exist are dropped.
                    $rows = array_map(fn ($row) => array_intersect_key($row, array_flip($columns)), $data['tables'][$table] ?? []);
                    foreach (array_chunk($rows, 200) as $chunk) {
                        DB::table($table)->insert($chunk);
                    }
                    $this->line(str_pad($table, 18).count($rows));
                }
            });
        });
        Cache::forget('settings');
        $this->info('Imported. Copy the photos to storage/app/public, then run php artisan storage:link and php artisan optimize.');

        return self::SUCCESS;
    }
}
