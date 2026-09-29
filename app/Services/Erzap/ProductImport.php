<?php

namespace App\Services\Erzap;

use App\Models\Category;
use App\Models\Product;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use OpenSpout\Reader\CSV\Options as CsvOptions;
use OpenSpout\Reader\CSV\Reader as CsvReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use RuntimeException;

/**
 * Imports products from an Erzap product export (XLSX or CSV).
 *
 * Erzap owns the product data it knows about: name, selling price, barcode and product code.
 * The website owns everything else and an import never touches it: photo, description, active/visible status,
 * hampers contents, sale period, outlet prices, and the category/variant of products that already exist.
 * New products are created inactive so staff choose what is sold as PO and add photos first.
 */
class ProductImport
{
    public const MAX_ROWS = 3000;

    /** Header names (lowercase, letters and digits only) recognised per field, most specific first. */
    public const FIELDS = [
        'barcode' => ['barcode', 'barcodeproduk', 'kodebarcode', 'barcodeitem'],
        'sku' => ['kode', 'kodeproduk', 'sku', 'kodebarang', 'kodeitem', 'plu', 'produkkode'],
        'name' => ['nama', 'namaproduk', 'produk', 'namabarang', 'namaitem', 'productname', 'produknama', 'name'],
        'price' => ['hargajual', 'harga', 'hargajualumum', 'price', 'hargajualoutlet'],
        'category' => ['kategori', 'kategorinama', 'kategoriproduk', 'namakategori', 'category'],
        'variant' => ['ukuran', 'produkukurannama', 'ukurannama', 'varian', 'variant', 'size'],
        // Optional, only used when a product is created: "Ya" marks it as hampers, contents one per line or separated by ";".
        'hamper' => ['hampers', 'hamper', 'produkhampers'],
        'hamper_contents' => ['isihampers', 'isihamper', 'isipaket', 'hampercontents'],
    ];

    public const LABELS = ['barcode' => 'Barcode', 'sku' => 'Product code', 'name' => 'Name', 'price' => 'Selling price', 'category' => 'Category', 'variant' => 'Size / variant', 'hamper' => 'Hampers (Ya/Tidak)', 'hamper_contents' => 'Hampers contents'];

    /** Reads the first sheet: first non-empty row is the header. Returns [headers, rows]. */
    public function read(string $path, string $extension): array
    {
        $reader = match (strtolower($extension)) {
            'xlsx' => new XlsxReader,
            'csv', 'txt' => new CsvReader($this->csvOptions($path)),
            default => throw new RuntimeException('Upload an .xlsx or .csv file exported from Erzap.'),
        };
        $reader->open($path);
        $headers = null;
        $rows = [];
        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    $values = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $row->toArray());
                    if (! array_filter($values, fn ($value) => $value !== null && $value !== '')) {
                        continue;
                    }
                    if ($headers === null) {
                        $headers = array_map(fn ($value) => (string) $value, $values);

                        continue;
                    }
                    if (count($rows) >= self::MAX_ROWS) {
                        throw new RuntimeException('The file has more than '.self::MAX_ROWS.' products. Split it into smaller files.');
                    }
                    $rows[] = $values;
                }
                break;
            }
        } finally {
            $reader->close();
        }
        if (! $headers || ! $rows) {
            throw new RuntimeException('The file has no product rows.');
        }

        return [$headers, $rows];
    }

    /** Column index per field, guessed from the header names. */
    public function detect(array $headers): array
    {
        $normalized = array_map(fn ($header) => preg_replace('/[^a-z0-9]/', '', strtolower($header)), $headers);
        $mapping = [];
        foreach (self::FIELDS as $field => $aliases) {
            $mapping[$field] = null;
            foreach ($aliases as $alias) {
                $index = array_search($alias, $normalized, true);
                if ($index !== false && ! in_array($index, $mapping, true)) {
                    $mapping[$field] = $index;
                    break;
                }
            }
        }

        return $mapping;
    }

    /** Problems that stop the import (missing columns). */
    public function mappingErrors(array $mapping): array
    {
        $errors = [];
        if ($mapping['name'] === null) {
            $errors[] = 'Choose the column with the product name.';
        }
        if ($mapping['barcode'] === null && $mapping['sku'] === null) {
            $errors[] = 'Choose the barcode column (needed to send orders to Erzap) or at least the product code column.';
        }

        return $errors;
    }

    /**
     * What the import would do per row, against the current catalog. Nothing is saved.
     * Matching order: barcode, then product code (SKU), then same name and variant on a product that has no barcode yet.
     * Names of existing products change only with $updateNames: Erzap often puts the size in the name ("Half …"),
     * while the order form groups sizes by one shared name.
     */
    public function plan(array $rows, array $mapping, bool $updateNames = false): array
    {
        $products = Product::with('category:id,name')->get();
        $byBarcode = $products->filter(fn ($p) => filled($p->barcode))->keyBy(fn ($p) => mb_strtolower($p->barcode));
        $bySku = $products->keyBy(fn ($p) => mb_strtolower($p->sku));
        $byName = $products->filter(fn ($p) => blank($p->barcode))->keyBy(fn ($p) => $this->nameKey($p->name, $p->variant));
        $seen = [];
        $claimed = [];
        $plan = [];
        foreach ($rows as $index => $row) {
            $get = fn (string $field) => $mapping[$field] === null ? null : ($row[$mapping[$field]] ?? null);
            $item = [
                'line' => $index + 2,
                'barcode' => $this->text($get('barcode'), 80), 'sku' => $this->text($get('sku'), 80), 'name' => $this->text($get('name'), 160),
                'price' => $this->price($get('price')), 'category' => $this->text($get('category'), 120), 'variant' => $this->text($get('variant'), 40),
                'hamper' => in_array(mb_strtolower(trim((string) $get('hamper'))), ['ya', 'y', 'yes', '1', 'true', 'hampers'], true),
                'hamper_contents' => $this->contents($get('hamper_contents')),
            ];
            $key = mb_strtolower($item['barcode'] ?? 'sku:'.$item['sku']);
            if (! $item['name'] || (! $item['barcode'] && ! $item['sku'])) {
                $plan[] = [...$item, 'action' => 'skip', 'reason' => ! $item['name'] ? 'No product name.' : 'No barcode or product code.'];

                continue;
            }
            if (isset($seen[$key])) {
                $plan[] = [...$item, 'action' => 'skip', 'reason' => 'Same barcode/code as line '.$seen[$key].'.'];

                continue;
            }
            $seen[$key] = $item['line'];

            [$product, $matchedBy] = match (true) {
                $item['barcode'] && $byBarcode->has(mb_strtolower($item['barcode'])) => [$byBarcode[mb_strtolower($item['barcode'])], 'barcode'],
                $item['sku'] && $bySku->has(mb_strtolower($item['sku'])) => [$bySku[mb_strtolower($item['sku'])], 'product code'],
                $byName->has($this->nameKey($item['name'], $item['variant'])) => [$byName[$this->nameKey($item['name'], $item['variant'])], 'name'],
                default => [null, null],
            };
            if ($product && isset($claimed[$product->id])) {
                $plan[] = [...$item, 'action' => 'skip', 'reason' => 'Matches the same website product as line '.$claimed[$product->id].'.'];

                continue;
            }

            if (! $product) {
                $plan[] = [...$item, 'action' => 'create', 'changes' => [], 'warning' => $item['barcode'] ? null : 'No barcode: orders with this product cannot be sent to Erzap.'];

                continue;
            }
            $claimed[$product->id] = $item['line'];
            $changes = [];
            if ($updateNames && $item['name'] !== $product->name) {
                $changes['name'] = [$product->name, $item['name']];
            }
            if ($item['price'] !== null && $item['price'] !== $product->price) {
                $changes['price'] = [$product->price, $item['price']];
            }
            if ($item['barcode'] && $item['barcode'] !== $product->barcode && ! $this->barcodeTaken($products, $item['barcode'], $product->id)) {
                $changes['barcode'] = [$product->barcode, $item['barcode']];
            }
            $sku = $item['sku'] ? $this->sku($item['sku']) : null;
            if ($sku && $sku !== $product->sku && ! $products->contains(fn ($p) => $p->id !== $product->id && mb_strtolower($p->sku) === mb_strtolower($sku))) {
                $changes['sku'] = [$product->sku, $sku];
            }
            $plan[] = [
                ...$item, 'action' => $changes ? 'update' : 'unchanged', 'changes' => $changes, 'product_id' => $product->id,
                'current' => $product->label(), 'matched_by' => $matchedBy,
                'warning' => ! ($changes['barcode'][1] ?? $product->barcode) ? 'No barcode: orders with this product cannot be sent to Erzap.' : null,
            ];
        }

        return $plan;
    }

    public function summary(array $plan): array
    {
        $counts = array_count_values(array_column($plan, 'action'));

        return ['create' => $counts['create'] ?? 0, 'update' => $counts['update'] ?? 0, 'unchanged' => $counts['unchanged'] ?? 0, 'skip' => $counts['skip'] ?? 0];
    }

    /** Applies a plan built moments before (re-planned inside the transaction so it reflects the current catalog). */
    public function apply(array $rows, array $mapping, ?int $actor, string $fileName, bool $updateNames = false): array
    {
        return DB::transaction(function () use ($rows, $mapping, $actor, $fileName, $updateNames) {
            $plan = $this->plan($rows, $mapping, $updateNames);
            $categories = Category::all()->keyBy(fn ($c) => mb_strtolower($c->name));
            foreach ($plan as $item) {
                if ($item['action'] === 'update') {
                    $product = Product::lockForUpdate()->find($item['product_id']);
                    $product->forceFill(array_map(fn ($change) => $change[1], $item['changes']))->save();
                    Audit::record('catalog.erzap_import_updated', $product, $item['changes'], $actor);
                } elseif ($item['action'] === 'create') {
                    $categoryName = $item['category'] ?: 'Imported from Erzap';
                    // New categories stay off the homepage Baked Goods until staff choose them.
                    $category = $categories[mb_strtolower($categoryName)] ??= Category::create(['name' => $categoryName, 'is_active' => true]);
                    $product = Product::create([
                        'category_id' => $category->id, 'name' => $item['name'], 'variant' => $item['variant'], 'barcode' => $item['barcode'],
                        'is_hamper' => $item['hamper'] || $item['hamper_contents'] !== null, 'hamper_contents' => $item['hamper_contents'],
                        'sku' => $this->uniqueSku($item['sku'] ?? $item['barcode']), 'price' => $item['price'], 'is_active' => false,
                    ]);
                    Audit::record('catalog.erzap_import_created', $product, $product->only('name', 'variant', 'barcode', 'sku', 'price'), $actor);
                }
            }
            $summary = $this->summary($plan);
            Audit::log('catalog.erzap_import', 'products', 0, [...$summary, 'file' => $fileName], $actor);

            return $summary;
        });
    }

    /** Hampers contents: one item per line; ";" also separates items. */
    private function contents(mixed $value): ?string
    {
        $lines = array_filter(array_map('trim', preg_split('/\r\n|\r|\n|;/', (string) $value)), fn ($line) => $line !== '');

        return $lines ? mb_substr(implode("\n", $lines), 0, 2000) : null;
    }

    private function nameKey(string $name, ?string $variant): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $name))).'|'.mb_strtolower(trim((string) $variant));
    }

    private function text(mixed $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }
        if (is_float($value) && floor($value) === $value) {
            $value = (string) (int) $value;
        }
        $value = trim(preg_replace('/\s+/u', ' ', (string) $value));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    /** Accepts 60000, "60000.0", "Rp 60.000", "60.000,00" and "60,000". */
    public function price(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return $value >= 1 ? (int) round($value) : null;
        }
        $text = preg_replace('/\s+|rp\.?/iu', '', (string) $value);
        $number = match (true) {
            // Dots before groups of three are thousands (Indonesian "60.000"); rupiah prices never have three decimals.
            (bool) preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $text) => (float) str_replace(['.', ','], ['', '.'], $text),
            (bool) preg_match('/^\d+(\.\d+)?$/', $text) => (float) $text,
            (bool) preg_match('/^\d{1,3}(,\d{3})+(\.\d+)?$/', $text) => (float) str_replace(',', '', $text),
            (bool) preg_match('/^\d+,\d{1,2}$/', $text) => (float) str_replace(',', '.', $text),
            default => null,
        };

        return $number !== null && $number >= 1 && $number <= 999999999 ? (int) round($number) : null;
    }

    private function barcodeTaken($products, string $barcode, int $productId): bool
    {
        return $products->contains(fn ($p) => $p->id !== $productId && mb_strtolower((string) $p->barcode) === mb_strtolower($barcode));
    }

    /** SKUs are letters, digits, dashes and underscores. */
    private function sku(string $value): string
    {
        return mb_substr(trim(preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-'), 0, 80) ?: 'ERZ';
    }

    private function uniqueSku(string $value): string
    {
        $base = $this->sku($value);
        $sku = $base;
        for ($i = 2; Product::where('sku', $sku)->exists(); $i++) {
            $sku = mb_substr($base, 0, 74).'-'.$i;
        }

        return $sku;
    }

    /** Erzap exports may use comma or semicolon separators. */
    private function csvOptions(string $path): CsvOptions
    {
        $options = new CsvOptions;
        $first = (string) fgets(fopen($path, 'r'));
        $options->FIELD_DELIMITER = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');

        return $options;
    }
}
