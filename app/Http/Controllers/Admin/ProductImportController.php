<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Services\Erzap\ProductImport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Inertia\Inertia;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;
use RuntimeException;
use Throwable;

/** Upload an Erzap product export, review what changes, then apply. Nothing is saved before "Apply". */
class ProductImportController extends Controller
{
    /** Header names of the Excel template; each one is recognised by ProductImport::detect(). */
    public const TEMPLATE_COLUMNS = ['Kode Produk', 'Barcode', 'Nama Produk', 'Kategori', 'Ukuran', 'Harga Jual', 'Tipe', 'Jumlah Isi', 'Isi Dari', 'Harga Isi'];

    public function show(Request $request, ProductImport $import)
    {
        $upload = $this->upload($request);
        $plan = $upload ? $import->plan($upload['rows'], $upload['mapping'], $upload['update_names'] ?? false) : [];

        return Inertia::render('Admin/Catalog/Import', [
            'fields' => ProductImport::LABELS,
            'upload' => $upload ? [
                'token' => $request->query('token'), 'file' => $upload['file'], 'headers' => $upload['headers'], 'mapping' => $upload['mapping'],
                'update_names' => $upload['update_names'] ?? false,
                'errors' => $import->mappingErrors($upload['mapping']), 'summary' => $import->summary($plan),
                // Unchanged rows are only counted, so the preview stays small for a full catalog.
                'rows' => array_values(array_filter($plan, fn ($row) => $row['action'] !== 'unchanged')),
            ] : null,
        ]);
    }

    public function store(Request $request, ProductImport $import)
    {
        $request->validate(['file' => ['required', 'file', 'max:5120', 'mimes:xlsx,csv,txt']], ['file.mimes' => 'Upload an .xlsx or .csv file exported from Erzap.']);
        $file = $request->file('file');
        try {
            [$headers, $rows] = $import->read($file->getRealPath(), $file->getClientOriginalExtension());
        } catch (RuntimeException $e) {
            return back()->withErrors(['file' => $e->getMessage()]);
        } catch (Throwable $e) {
            report($e);

            return back()->withErrors(['file' => 'The file could not be read. Export it again from Erzap as Excel (.xlsx) or CSV.']);
        }
        $token = (string) Str::uuid();
        Cache::put($this->key($token), [
            'user' => $request->user()->id, 'file' => Str::limit($file->getClientOriginalName(), 120),
            'headers' => $headers, 'rows' => $rows, 'mapping' => $import->detect($headers), 'update_names' => false,
        ], now()->addHour());

        return redirect('/admin/products/import?token='.$token);
    }

    /** Staff can correct which column holds which field when the header names differ. */
    public function mapping(Request $request)
    {
        $upload = $this->upload($request, true);
        $columns = count($upload['headers']);
        $data = $request->validate(collect(ProductImport::FIELDS)->keys()->mapWithKeys(fn ($field) => [
            'mapping.'.$field => ['nullable', 'integer', 'min:0', 'max:'.($columns - 1)],
        ])->all());
        $mapping = collect(ProductImport::FIELDS)->keys()->mapWithKeys(fn ($field) => [$field => isset($data['mapping'][$field]) ? (int) $data['mapping'][$field] : null])->all();
        $used = array_filter($mapping, fn ($index) => $index !== null);
        if (count($used) !== count(array_unique($used))) {
            return back()->withErrors(['mapping' => 'Each column can only be used for one field.']);
        }
        Cache::put($this->key($request->input('token')), [...$upload, 'mapping' => $mapping, 'update_names' => $request->boolean('update_names')], now()->addHour());

        return redirect('/admin/products/import?token='.$request->input('token'));
    }

    public function apply(Request $request, ProductImport $import)
    {
        $upload = $this->upload($request, true);
        if ($errors = $import->mappingErrors($upload['mapping'])) {
            return back()->withErrors(['mapping' => $errors[0]]);
        }
        $summary = $import->apply($upload['rows'], $upload['mapping'], $request->user()->id, $upload['file'], $upload['update_names'] ?? false);
        Cache::forget($this->key($request->input('token')));

        return redirect('/admin/products')->with('success', "Import finished: {$summary['create']} new (inactive), {$summary['update']} updated, {$summary['unchanged']} unchanged, {$summary['skip']} skipped.");
    }

    /**
     * Excel template with the column names the import recognises, pre-filled with the current catalog
     * so staff only add the Erzap code and barcode (and correct prices) before importing it back.
     * Only the first sheet is imported; "Contoh" shows a regular product, a hampers and a Mini box.
     */
    public function template()
    {
        $path = tempnam(sys_get_temp_dir(), 'orlena-import');
        $writer = new Writer;
        $writer->openToFile($path);
        $header = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('4A2C23');
        // Green: contents chosen by the customer (hampers and boxes only).
        $contentHeader = (new Style)->setFontBold()->setFontColor('FFFFFF')->setBackgroundColor('4D7141');
        $headerRow = new Row(array_map(fn (string $column, int $index) => Cell::fromValue($column, $index < 6 ? $header : $contentHeader), self::TEMPLATE_COLUMNS, array_keys(self::TEMPLATE_COLUMNS)));
        $text = (new Style)->setFormat('@');
        $money = (new Style)->setFormat('#,##0');

        $sheet = $writer->getCurrentSheet()->setName('Produk');
        $sheet->setColumnWidth(18, 1, 2);
        $sheet->setColumnWidth(36, 3);
        $sheet->setColumnWidth(24, 4);
        $sheet->setColumnWidth(14, 5, 6, 7, 8, 10);
        $sheet->setColumnWidth(40, 9);
        $writer->addRow($headerRow);
        Product::with('category:id,name', 'contentOptions:id,sku')->orderBy('category_id')->orderBy('name')->orderBy('variant')->get()
            ->each(fn (Product $product) => $writer->addRow(new Row([
                // Codes and barcodes stay text so leading zeros survive.
                Cell::fromValue((string) $product->sku, $text), Cell::fromValue((string) $product->barcode, $text),
                Cell::fromValue($product->name), Cell::fromValue((string) $product->category?->name),
                Cell::fromValue((string) $product->variant), Cell::fromValue($product->price ?? '', $money),
                Cell::fromValue(Product::TYPES[$product->content_type] ?? 'Biasa'), Cell::fromValue(in_array($product->content_type, ['hampers', 'box', 'set'], true) ? (int) $product->content_count : ''),
                // A fixed box writes "2x BR0054" for repeated flavours.
                Cell::fromValue($product->contentOptions->map(fn (Product $option) => ($option->pivot->quantity > 1 ? $option->pivot->quantity.'x ' : '').$option->sku)->implode(', '), $text),
                Cell::fromValue(Product::CONTENT_PRICING[$product->content_pricing] ?? ''),
            ])));

        $examples = $writer->addNewSheetAndMakeItCurrent()->setName('Contoh');
        $examples->setColumnWidth(18, 1, 2);
        $examples->setColumnWidth(30, 3);
        $examples->setColumnWidth(18, 4, 5);
        $examples->setColumnWidth(12, 6, 7, 8, 10);
        $examples->setColumnWidth(34, 9);
        $examples->setColumnWidth(60, 11);
        $writer->addRow(new Row([...$headerRow->getCells(), Cell::fromValue('Keterangan', $header)]));
        foreach ([
            ['BR0005', '8990005', 'Berry Cheese', 'Brownies', 'Fullsize', 100000, 'Biasa', '', '', '', 'Produk biasa. Setiap ukuran baris sendiri.'],
            ['BR0037', '8990037', 'Berry Cheese', 'Brownies', 'Halfsize (20x10)', 60000, 'Biasa', '', '', '', 'Ukuran lain = produk lain dengan barcode sendiri.'],
            ['HP0001', '8991001', 'Hampers Galungan', 'Hampers', 'Fullsize', 10000, 'Hampers', 1, 'BR0005, BR0007', 'Ditambah', 'Pelanggan memilih 1 isi: harga = 10.000 + harga brownies.'],
            ['MN0001', '8992001', 'Mini Choco', 'Mini Size', 'Mini', 0, 'Rasa box', '', '', '', 'Rasa Mini: harga 0, tidak tampil sendiri (dijual di dalam box saja).'],
            ['MN0002', '8992002', 'Mini Cheese', 'Mini Size', 'Mini', 0, 'Rasa box', '', '', '', ''],
            ['MB0001', '8992100', 'Mini Size Box', 'Mini Size', 'Box 2x2', 60000, 'Box', 4, 'MN0001, MN0002', 'Termasuk', 'Pelanggan memilih 4 rasa (boleh sama); harga per box.'],
            ['MB0002', '8992101', 'Mini Box Classic', 'Mini Size', 'Box 2x2', 55000, 'Box isi tetap', '', '2x MN0001, 2x MN0002', '', 'Rasa sudah ditentukan: pelanggan langsung menambahkan.'],
        ] as $example) {
            $writer->addRow(new Row([
                Cell::fromValue($example[0], $text), Cell::fromValue($example[1], $text), ...array_map(fn ($value) => Cell::fromValue($value), array_slice($example, 2, 3)),
                Cell::fromValue($example[5], $money), ...array_map(fn ($value) => Cell::fromValue($value), array_slice($example, 6)),
            ]));
        }

        $guide = $writer->addNewSheetAndMakeItCurrent()->setName('Petunjuk');
        $guide->setColumnWidth(22, 1);
        $guide->setColumnWidth(90, 2);
        $bold = (new Style)->setFontBold();
        $writer->addRow(Row::fromValues(['Format import produk Orlena'], $bold));
        $writer->addRow(Row::fromValues(['Upload file ini di Dashboard > Products > Import from Erzap. Hanya sheet pertama (Produk) yang dibaca; baris pertama wajib berisi nama kolom. Lihat sheet Contoh.']));
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Kolom', 'Keterangan'], $bold));
        foreach ([
            ['Kode Produk', 'Kode produk di Erzap. Wajib diisi bila Barcode kosong.'],
            ['Barcode', 'Barcode produk di Erzap. Wajib agar pesanan dapat dikirim ke Erzap. Harus unik per produk dan varian.'],
            ['Nama Produk', 'Wajib. Nama tanpa ukuran, misalnya "Berry Cheese". Di website tampil Nama + Ukuran.'],
            ['Kategori', 'Dipakai untuk produk baru saja; kategori produk yang sudah ada tidak diubah.'],
            ['Ukuran', 'Misalnya Fullsize, Halfsize (20x10), Box 2x2. Setiap ukuran adalah produk sendiri (kartu, foto, harga dan barcode sendiri). Dipakai untuk produk baru saja.'],
            ['Harga Jual', 'Angka rupiah tanpa titik, misalnya 80000. Format Rp 80.000 juga diterima. Isi box (misalnya rasa Mini Size) = 0.'],
            ['Tipe', 'Biasa, Hampers (pelanggan memilih 1 isi), Box (pelanggan memilih beberapa rasa, boleh sama), Box isi tetap (rasa sudah ditentukan) atau Rasa box (rasa Mini, Rp 0, hanya di dalam box). Kosong = tidak diubah.'],
            ['Jumlah Isi', 'Box: berapa rasa yang dipilih per box, misalnya 4 untuk 2x2. Hampers selalu 1. Box isi tetap dihitung dari Isi Dari.'],
            ['Isi Dari', 'Kode Produk (atau Barcode) yang boleh dipilih sebagai isi, dipisah koma. Box isi tetap: tulis jumlahnya, misalnya "2x MN0001, 2x MN0002". Produk baru tidak otomatis masuk: tambahkan kodenya di sini.'],
            ['Harga Isi', 'Ditambah = harga hampers + harga normal isinya. Termasuk = isi Rp 0 (harga per box). Kosong: Hampers = Ditambah, Box = Termasuk.'],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Aturan import'], $bold));
        foreach ([
            'Produk dicocokkan berdasarkan Barcode, lalu Kode Produk, lalu Nama Produk + Ukuran.',
            'Produk yang sudah ada: hanya nama, harga jual, barcode, dan kode produk yang diperbarui.',
            'Foto, deskripsi, kategori, ukuran, status aktif, dan periode penjualan tidak pernah diubah. Tipe dan isi pilihan diubah bila kolom Tipe diisi.',
            'Produk baru masuk dalam keadaan nonaktif. Upload foto dulu, lalu aktifkan di menu Products.',
            'Baris tanpa nama, atau tanpa barcode dan kode, dilewati. Semua perubahan tampil di pratinjau sebelum disimpan.',
            'File export dari Erzap juga bisa langsung diupload bila nama kolomnya mirip; kolom bisa dipilih manual di halaman import.',
        ] as $rule) {
            $writer->addRow(Row::fromValues(['', $rule]));
        }
        $writer->close();

        return response()->download($path, 'format-import-produk-orlena.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    /** The uploaded file of the current user, or null (or 404 when $required) if it expired. */
    private function upload(Request $request, bool $required = false): ?array
    {
        $token = (string) ($request->input('token') ?? $request->query('token'));
        $upload = Str::isUuid($token) ? Cache::get($this->key($token)) : null;
        if (! $upload || $upload['user'] !== $request->user()->id) {
            abort_if($required, 410, 'This upload expired. Upload the file again.');

            return null;
        }

        return $upload;
    }

    private function key(string $token): string
    {
        return 'erzap-product-import:'.$token;
    }
}
