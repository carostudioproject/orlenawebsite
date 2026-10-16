<?php

namespace App\Console\Commands;

use App\Support\CatalogTransfer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Writes the catalog, website content and settings to one JSON file for `orlena:import-catalog` on another server. */
class ExportCatalog extends Command
{
    protected $signature = 'orlena:export-catalog {path=storage/app/private/catalog-export.json}';

    protected $description = 'Export products, categories, outlets, content and settings (no orders, customers or users) to JSON';

    public function handle(): int
    {
        $tables = [];
        foreach (CatalogTransfer::TABLES as $table) {
            $columns = array_intersect(CatalogTransfer::USER_COLUMNS, Schema::getColumnListing($table));
            $tables[$table] = DB::table($table)->get()->map(fn ($row) => array_merge((array) $row, array_fill_keys($columns, null)))->all();
        }
        $path = base_path($this->argument('path'));
        $json = json_encode(['version' => CatalogTransfer::VERSION, 'exported_at' => now()->toIso8601String(), 'migration' => DB::table('migrations')->max('migration'), 'tables' => $tables],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        if (file_put_contents($path, $json) === false) {
            $this->error('Cannot write '.$path);

            return self::FAILURE;
        }
        foreach ($tables as $table => $rows) {
            $this->line(str_pad($table, 18).count($rows));
        }
        $this->info('Exported to '.$path.'. Copy storage/app/public too (photos).');

        return self::SUCCESS;
    }
}
