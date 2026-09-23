@php
    $rupiah = fn ($amount) => 'Rp '.number_format((int) $amount, 0, ',', '.');
    $date = fn ($value) => \Carbon\CarbonImmutable::parse($value)->format('j F Y');
    $orderLabels = ['pending_review' => 'Awaiting review', 'confirmed' => 'Confirmed', 'processing' => 'Processing', 'ready' => 'Ready', 'delivering' => 'Out for delivery', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
    $paymentLabels = ['not_created' => 'No payment yet', 'creating' => 'Creating link', 'creation_failed' => 'Link failed', 'pending' => 'Awaiting payment', 'paid' => 'Paid', 'failed' => 'Payment failed', 'expired' => 'Link expired', 'cancelled' => 'Link cancelled', 'refunded' => 'Refunded'];
    $f = $report['filters'];
    $s = $report['summary'];
    $maxDay = max(1, ...array_column($report['daily'], 'revenue'));
    // dompdf needs the GD extension for PNG; without it the PDF uses a text wordmark instead of failing.
    $logo = $mode === 'print' || extension_loaded('gd') ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('assets/images/Orlena-Logo.png'))) : null;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="robots" content="noindex,nofollow">
    <title>Orlena Sales Report {{ $f['from'] }} to {{ $f['to'] }}</title>
    <style>
        @if ($mode === 'print')
        @font-face { font-family: 'Aileron'; src: url('/assets/fonts/aileron/Aileron-Regular.otf') format('opentype'); font-weight: 400; }
        @font-face { font-family: 'Aileron'; src: url('/assets/fonts/aileron/Aileron-Bold.otf') format('opentype'); font-weight: 700; }
        @endif
        @page { size: A4; margin: 16mm 14mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #3b0304; font-family: {{ $mode === 'print' ? "'Aileron', Arial, sans-serif" : "'DejaVu Sans', sans-serif" }}; font-size: {{ $mode === 'print' ? '12px' : '9.5px' }}; line-height: 1.45; background: #fff; }
        .page { max-width: 820px; margin: 0 auto; padding: {{ $mode === 'print' ? '24px' : '0' }}; }
        header { border-bottom: 3px solid #3b0304; padding-bottom: 10px; margin-bottom: 14px; }
        header img { height: 34px; }
        h1 { font-size: 1.6em; margin: 8px 0 2px; }
        h2 { font-size: 1.15em; margin: 18px 0 6px; padding-bottom: 4px; border-bottom: 1px solid #3b030422; }
        .muted { color: #6b3a3b; }
        .meta td { padding: 1px 12px 1px 0; }
        .tiles { width: 100%; border-collapse: separate; border-spacing: 6px; margin: 0 -6px; }
        .tiles td { background: #fff5f0; border: 1px solid #f7dbb3; border-radius: 6px; padding: 8px 10px; vertical-align: top; width: 25%; }
        .tiles .label { font-size: .85em; color: #6b3a3b; }
        .tiles .value { font-size: 1.35em; font-weight: 700; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; background: #f7dbb3; padding: 5px 6px; font-size: .9em; }
        table.data td { padding: 4px 6px; border-bottom: 1px solid #3b030414; }
        table.data .num { text-align: right; white-space: nowrap; }
        .bar { height: 7px; background: #db3e4c; border-radius: 3px; }
        .note { background: #fff5f0; border-left: 3px solid #75a4cc; padding: 6px 10px; margin-top: 10px; font-size: .9em; }
        footer { margin-top: 18px; font-size: .85em; color: #6b3a3b; border-top: 1px solid #3b030422; padding-top: 6px; }
        .actions { margin-bottom: 16px; display: flex; gap: 8px; }
        .actions button { font: inherit; font-weight: 700; border: 0; border-radius: 999px; padding: 8px 16px; background: #3b0304; color: #fff; cursor: pointer; }
        .actions button.secondary { background: #f7dbb3; color: #3b0304; }
        @media print { .actions { display: none; } .page { padding: 0; } }
        .keep { page-break-inside: avoid; }
    </style>
</head>
<body>
<div class="page">
    @if ($mode === 'print')
        <div class="actions"><button type="button" onclick="window.print()">Print / save as PDF</button><button type="button" class="secondary" onclick="window.close()">Close</button></div>
    @endif
    <header>
        @if ($logo)
            <img src="{{ $logo }}" alt="Orlena">
        @else
            <div style="font-size: 20px; font-weight: 700; letter-spacing: 4px;">ORLENA</div>
        @endif
        <h1>Sales &amp; Performance Report</h1>
        <table class="meta">
            <tr><td class="muted">Period</td><td><strong>{{ $date($f['from']) }} – {{ $date($f['to']) }}</strong> (by payment time, WITA)</td></tr>
            <tr><td class="muted">Outlet</td><td>{{ $report['labels']['outlet'] ?? 'All outlets' }}</td></tr>
            <tr><td class="muted">Product</td><td>{{ $report['labels']['product'] ?? 'All products' }}</td></tr>
            <tr><td class="muted">Order status</td><td>{{ $f['status'] ? ($orderLabels[$f['status']] ?? $f['status']) : 'All statuses' }}</td></tr>
        </table>
    </header>

    <table class="tiles">
        <tr>
            <td><div class="label">{{ $f['product_id'] ? 'Product sales' : 'Paid revenue' }}</div><div class="value">{{ $rupiah($s['revenue']) }}</div></td>
            <td><div class="label">Transactions</div><div class="value">{{ number_format($s['transactions'], 0, ',', '.') }}</div></td>
            <td><div class="label">Average / transaction</div><div class="value">{{ $rupiah($s['average']) }}</div></td>
            <td><div class="label">Items sold</div><div class="value">{{ number_format($s['items_sold'], 0, ',', '.') }}</div></td>
        </tr>
    </table>
    @unless ($f['product_id'])
        <p class="muted" style="margin:6px 0 0">Made up of product sales {{ $rupiah($s['product_sales']) }} and delivery fees {{ $rupiah($s['delivery_fees']) }}.</p>
    @endunless
    @if ($s['voided_orders'])
        <div class="note">{{ $s['voided_orders'] }} order(s) worth {{ $rupiah($s['voided_amount']) }} were cancelled or refunded after payment and are not counted as revenue.</div>
    @endif

    <div class="keep">
        <h2>Revenue by outlet</h2>
        @if (count($report['outlets']))
            <table class="data"><thead><tr><th>Outlet</th><th class="num">Transactions</th><th class="num">Revenue</th></tr></thead><tbody>
                @foreach ($report['outlets'] as $row)
                    <tr><td>{{ $row['outlet'] }}</td><td class="num">{{ $row['transactions'] }}</td><td class="num">{{ $rupiah($row['revenue']) }}</td></tr>
                @endforeach
            </tbody></table>
        @else
            <p class="muted">No paid transactions in this period.</p>
        @endif
    </div>

    <h2>Best-selling products</h2>
    @if (count($report['products']))
        <table class="data"><thead><tr><th>#</th><th>Product</th><th>Category</th><th class="num">Sold</th><th class="num">Orders</th><th class="num">Sales</th></tr></thead><tbody>
            @foreach ($report['products'] as $i => $row)
                <tr><td>{{ $i + 1 }}</td><td>{{ $row['name'] }}@if ($row['variant']) ({{ $row['variant'] }})@endif</td><td>{{ $row['category'] }}</td><td class="num">{{ $row['quantity'] }}</td><td class="num">{{ $row['orders'] }}</td><td class="num">{{ $rupiah($row['revenue']) }}</td></tr>
            @endforeach
        </tbody></table>
    @else
        <p class="muted">No products sold in this period.</p>
    @endif

    <h2>Daily revenue</h2>
    <table class="data"><thead><tr><th style="width:24%">Date</th><th class="num" style="width:12%">Transactions</th><th class="num" style="width:22%">Revenue</th><th></th></tr></thead><tbody>
        @foreach ($report['daily'] as $day)
            @if ($day['transactions'] || count($report['daily']) <= 31)
                <tr><td>{{ $date($day['date']) }}</td><td class="num">{{ $day['transactions'] }}</td><td class="num">{{ $rupiah($day['revenue']) }}</td><td><div class="bar" style="width: {{ round($day['revenue'] / $maxDay * 100) }}%"></div></td></tr>
            @endif
        @endforeach
    </tbody></table>
    @if (count($report['daily']) > 31)
        <p class="muted">Only dates with transactions are shown.</p>
    @endif

    <div class="keep">
        <h2>Order status summary</h2>
        <p class="muted" style="margin:0 0 6px">{{ $report['placed_orders'] }} order(s) placed in this period (by order date).</p>
        <table class="data" style="width:49%; float:left"><thead><tr><th>Order status</th><th class="num">Count</th></tr></thead><tbody>
            @forelse ($report['order_statuses'] as $status => $total)
                <tr><td>{{ $orderLabels[$status] ?? $status }}</td><td class="num">{{ $total }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">No orders.</td></tr>
            @endforelse
        </tbody></table>
        <table class="data" style="width:49%; float:right"><thead><tr><th>Payment status</th><th class="num">Count</th></tr></thead><tbody>
            @forelse ($report['payment_statuses'] as $status => $total)
                <tr><td>{{ $paymentLabels[$status] ?? $status }}</td><td class="num">{{ $total }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">No orders.</td></tr>
            @endforelse
        </tbody></table>
        <div style="clear:both"></div>
    </div>

    <footer>Generated {{ \Carbon\CarbonImmutable::parse($report['generated_at'])->format('j F Y, H:i') }} WITA from Orlena Ordering System data. This is not an accounting report.</footer>
</div>
@if ($mode === 'print')
    <script>window.addEventListener('load', () => setTimeout(() => window.print(), 300));</script>
@endif
</body>
</html>
