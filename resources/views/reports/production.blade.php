@php
    $day = \Carbon\CarbonImmutable::parse($recap['date'])->locale('id')->translatedFormat('l, j F Y');
    $label = fn (array $row) => $row['name'].($row['variant'] ? ' ('.$row['variant'].')' : '');
    $s = $recap['summary'];
    // dompdf needs the GD extension for PNG; without it the PDF uses a text wordmark instead of failing.
    $logo = extension_loaded('gd') ? 'data:image/png;base64,'.base64_encode(file_get_contents(public_path('assets/images/Orlena-Logo.png'))) : null;
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <title>Rekap CK Orlena {{ $recap['date'] }}</title>
    <style>
        @page { size: A4; margin: 14mm 12mm; }
        * { box-sizing: border-box; }
        body { margin: 0; color: #3b0304; font-family: 'DejaVu Sans', sans-serif; font-size: 9.5px; line-height: 1.4; }
        header { border-bottom: 3px solid #3b0304; padding-bottom: 8px; margin-bottom: 12px; }
        header img { height: 30px; }
        h1 { font-size: 16px; margin: 6px 0 2px; }
        h2 { font-size: 12px; margin: 16px 0 6px; padding-bottom: 3px; border-bottom: 1px solid #3b030422; }
        h3 { font-size: 11px; margin: 12px 0 4px; }
        .muted { color: #6b3a3b; }
        .status { display: inline-block; padding: 2px 8px; border-radius: 8px; font-weight: 700; background: {{ $recap['final'] ? '#4d7141' : '#db3e4c' }}; color: #fff; }
        table.data { width: 100%; border-collapse: collapse; }
        table.data th { text-align: left; background: #f7dbb3; padding: 4px 5px; font-size: 8.5px; }
        table.data td { padding: 3px 5px; border-bottom: 1px solid #3b030414; vertical-align: top; }
        table.data .num { text-align: right; white-space: nowrap; }
        table.data .total { font-weight: 700; }
        .order { border: 1px solid #3b030422; border-radius: 4px; padding: 5px 7px; margin-bottom: 5px; page-break-inside: avoid; }
        .contents { color: #6b3a3b; padding-left: 12px; }
        .card { background: #fff5f0; border-left: 3px solid #f3a8ac; padding: 3px 6px; margin-top: 3px; }
        .note { background: #fff5f0; border-left: 3px solid #75a4cc; padding: 5px 8px; margin-top: 8px; }
        footer { margin-top: 14px; font-size: 8px; color: #6b3a3b; border-top: 1px solid #3b030422; padding-top: 5px; }
    </style>
</head>
<body>
<header>
    @if ($logo)<img src="{{ $logo }}" alt="Orlena">@else<strong style="font-size:18px">Orlena</strong>@endif
    <h1>Rekap produksi (CK) · {{ $day }}</h1>
    <div><span class="status">{{ $recap['final'] ? 'FINAL' : 'SEMENTARA' }}</span>
        <span class="muted">{{ $recap['final'] ? 'Cut-off sudah lewat; daftar ini tidak berubah lagi.' : 'Cut-off belum lewat; pesanan lunas berikutnya masih bisa masuk.' }}</span></div>
    <p class="muted" style="margin:6px 0 0">Pesanan lunas: <strong>{{ $s['orders'] }}</strong> (pickup {{ $s['pickup'] }}, delivery {{ $s['delivery'] }}) · total produk: <strong>{{ $s['pieces'] }}</strong></p>
</header>

<h2>Total produksi</h2>
@if ($recap['products'])
    <table class="data">
        <thead><tr><th>Produk</th><th>Kategori</th>@foreach ($recap['outlets'] as $outlet)<th class="num">{{ $outlet }}</th>@endforeach<th class="num">Total</th></tr></thead>
        <tbody>
        @foreach ($recap['products'] as $row)
            <tr><td>{{ $label($row) }}</td><td class="muted">{{ $row['category'] }}</td>@foreach ($recap['outlets'] as $outlet)<td class="num">{{ $row['by_outlet'][$outlet] ?: '' }}</td>@endforeach<td class="num total">{{ $row['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@else
    <p class="muted">Belum ada pesanan lunas untuk tanggal ini.</p>
@endif

@if ($recap['packages'])
    <h2>Kemasan hampers dan box</h2>
    <table class="data">
        <thead><tr><th>Kemasan</th>@foreach ($recap['outlets'] as $outlet)<th class="num">{{ $outlet }}</th>@endforeach<th class="num">Total</th></tr></thead>
        <tbody>
        @foreach ($recap['packages'] as $row)
            <tr><td>{{ $label($row) }}</td>@foreach ($recap['outlets'] as $outlet)<td class="num">{{ $row['by_outlet'][$outlet] ?: '' }}</td>@endforeach<td class="num total">{{ $row['quantity'] }}</td></tr>
        @endforeach
        </tbody>
    </table>
@endif

@if ($recap['unpaid'])
    <p class="note">{{ $recap['unpaid'] }} pesanan untuk tanggal ini sudah dikonfirmasi tetapi belum lunas, dan belum termasuk di rekap ini.</p>
@endif

@foreach ($recap['orders'] as $group)
    <h2>{{ $group['outlet'] }} · {{ count($group['orders']) }} pesanan</h2>
    @foreach ($group['orders'] as $order)
        <div class="order">
            <strong>{{ $order['time'] ?? '—' }} · {{ $order['method'] === 'delivery' ? 'Delivery' : 'Pickup' }}</strong> · {{ $order['code'] }} · {{ $order['customer'] }}
            @foreach ($order['items'] as $item)
                <div>{{ $item['label'] }}</div>
                @foreach ($item['contents'] as $content)<div class="contents">isi: {{ $content }}</div>@endforeach
            @endforeach
            @if ($order['card'])<div class="card">Kartu ucapan: {{ $order['card'] }}</div>@endif
            @if ($order['note'])<div class="muted">Catatan: {{ $order['note'] }}</div>@endif
        </div>
    @endforeach
@endforeach

<footer>Dibuat {{ now('Asia/Makassar')->locale('id')->translatedFormat('j F Y, H.i') }} WITA dari dashboard Orlena. Hanya pesanan yang sudah lunas dan tidak dibatalkan.</footer>
</body>
</html>
