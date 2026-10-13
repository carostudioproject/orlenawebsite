<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Reports\ProductionRecap;
use App\Support\Audit;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Rekap CK: what the central kitchen produces for one date, as a dashboard page, a PDF and a WhatsApp message. */
class ProductionController extends Controller
{
    public function index(Request $request, ProductionRecap $recap)
    {
        $data = $recap->build($this->date($request));

        return Inertia::render('Admin/Production', ['recap' => $data, 'whatsappUrl' => $recap->whatsappUrl($data), 'whatsappText' => $recap->whatsappText($data)]);
    }

    public function pdf(Request $request, ProductionRecap $recap)
    {
        $data = $recap->build($this->date($request));
        $options = new Options;
        // Only local, inlined assets: no remote fetches while rendering.
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('reports.production', ['recap' => $data])->render());
        $pdf->setPaper('A4');
        $pdf->render();
        Audit::log('report.production_exported', 'reports', 0, ['date' => $data['date']]);

        return response($pdf->output(), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="rekap-ck-orlena-'.$data['date'].'.pdf"',
        ]);
    }

    /** The requested date, tomorrow (the date the kitchen is preparing) by default. */
    private function date(Request $request): string
    {
        $data = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);

        return $data['date'] ?? now('Asia/Makassar')->addDay()->toDateString();
    }
}
