<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Outlet;
use App\Models\Product;
use App\Services\Reports\SalesReport;
use App\Support\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Inertia\Inertia;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Sales and performance: on-screen report, browser print, PDF and Excel, all from the same figures. */
class ReportController extends Controller
{
    public function index(Request $request, SalesReport $report)
    {
        $data = $report->build($request->validate(SalesReport::rules()));

        return Inertia::render('Admin/Reports/Index', [
            'report' => [...$data, 'products' => array_slice($data['products'], 0, 20)],
            'productCount' => count($data['products']),
            'outlets' => Outlet::orderBy('position')->orderBy('name')->get(['id', 'name']),
            'products' => Product::orderBy('name')->orderBy('variant')->get(['id', 'name', 'variant'])->map(fn ($p) => ['id' => $p->id, 'label' => $p->label()]),
        ]);
    }

    public function print(Request $request, SalesReport $report)
    {
        return view('reports.sales', ['report' => $report->build($request->validate(SalesReport::rules())), 'mode' => 'print']);
    }

    public function pdf(Request $request, SalesReport $report)
    {
        $data = $report->build($request->validate(SalesReport::rules()));
        $options = new Options;
        // Only local, inlined assets: no remote fetches while rendering.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.sales', ['report' => $data, 'mode' => 'pdf'])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        Audit::log('report.exported', 'reports', 0, ['format' => 'pdf', ...$data['filters']]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$this->filename($data, 'pdf').'"',
        ]);
    }

    public function excel(Request $request, SalesReport $report)
    {
        $data = $report->build($request->validate(SalesReport::rules()));
        $path = tempnam(sys_get_temp_dir(), 'orlena-report');
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->setFontBold();
        $money = (new Style)->setFormat('#,##0');
        $f = $data['filters'];

        $writer->getCurrentSheet()->setName('Summary');
        $writer->addRow(Row::fromValues(['Orlena Sales Report'], $bold));
        $writer->addRow(Row::fromValues(['Period', $f['from'].' to '.$f['to']]));
        $writer->addRow(Row::fromValues(['Outlet', $data['labels']['outlet'] ?? 'All outlets']));
        $writer->addRow(Row::fromValues(['Product', $data['labels']['product'] ?? 'All products']));
        $writer->addRow(Row::fromValues(['Order status', $f['status'] ?? 'All statuses']));
        $writer->addRow(Row::fromValues([]));
        $summary = $data['summary'];
        foreach ([
            ['Paid revenue (Rp)', $summary['revenue']], ['Transactions', $summary['transactions']], ['Average per transaction (Rp)', $summary['average']],
            ['Product sales (Rp)', $summary['product_sales']], ['Delivery fees (Rp)', $summary['delivery_fees'] ?? '-'], ['Items sold', $summary['items_sold']],
            ['Cancelled/refunded after payment (orders)', $summary['voided_orders']], ['Cancelled/refunded after payment (Rp)', $summary['voided_amount']],
        ] as $line) {
            $writer->addRow(Row::fromValues($line));
        }

        $this->sheet($writer, 'Daily', ['Date', 'Transactions', 'Revenue (Rp)'], array_map(fn ($d) => [$d['date'], $d['transactions'], $d['revenue']], $data['daily']), $bold, $money);
        $this->sheet($writer, 'By outlet', ['Outlet', 'Transactions', 'Revenue (Rp)'], array_map(fn ($o) => [$o['outlet'], $o['transactions'], $o['revenue']], $data['outlets']), $bold, $money);
        $this->sheet($writer, 'Best sellers', ['Rank', 'Product', 'Variant', 'Category', 'Quantity sold', 'Orders', 'Sales (Rp)'],
            array_map(fn ($p, $i) => [$i + 1, $p['name'], $p['variant'] ?? '', $p['category'], $p['quantity'], $p['orders'], $p['revenue']], $data['products'], array_keys($data['products'])), $bold, $money);
        $statuses = [];
        foreach ($data['order_statuses'] as $status => $total) {
            $statuses[] = ['Order status', $status, $total];
        }
        foreach ($data['payment_statuses'] as $status => $total) {
            $statuses[] = ['Payment status', $status, $total];
        }
        $this->sheet($writer, 'Status', ['Type', 'Status', 'Orders'], $statuses, $bold, $money);
        $writer->close();
        Audit::log('report.exported', 'reports', 0, ['format' => 'xlsx', ...$f]);

        return response()->download($path, $this->filename($data, 'xlsx'), ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend();
    }

    private function sheet(Writer $writer, string $name, array $header, array $rows, Style $bold, Style $money): void
    {
        $writer->addNewSheetAndMakeItCurrent()->setName($name);
        $writer->addRow(Row::fromValues($header, $bold));
        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues($row, is_int(end($row)) ? $money : null));
        }
    }

    private function filename(array $data, string $extension): string
    {
        return 'orlena-sales-report-'.$data['filters']['from'].'-to-'.$data['filters']['to'].'.'.$extension;
    }
}
