<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Nota {{ $transaction->invoice_no }}</title>
    <style>
        :root { color-scheme: light; font-family: Arial, sans-serif; color: #171717; }
        body { margin: 0; padding: 24px 12px; background: #f3f4f6; }
        .receipt { box-sizing: border-box; width: min(100%, 420px); margin: auto; padding: 24px; background: white; }
        header, .totals { text-align: center; }
        h1 { margin: 0 0 4px; font-size: 22px; }
        .muted { color: #525252; font-size: 13px; }
        .rule { border: 0; border-top: 1px dashed #737373; margin: 16px 0; }
        .meta, .totals div { display: flex; justify-content: space-between; gap: 12px; margin: 7px 0; font-size: 14px; }
        table { width: 100%; border-collapse: collapse; font-size: 14px; }
        th { padding-bottom: 8px; text-align: left; }
        th:last-child, td:last-child { text-align: right; }
        td { padding: 5px 0; vertical-align: top; }
        .item-name { font-weight: 700; }
        .totals .grand-total { margin-top: 12px; font-size: 18px; font-weight: 700; }
        .thanks { margin: 20px 0 0; text-align: center; font-size: 13px; }
        .actions { display: flex; justify-content: center; margin: 0 auto 16px; }
        button { border: 0; border-radius: 8px; padding: 12px 20px; background: #166534; color: white; font-size: 16px; }
        @media print {
            @page { size: 58mm auto; margin: 3mm; }
            body { padding: 0; background: white; }
            .receipt { width: 100%; padding: 0; }
            .actions { display: none; }
        }
    </style>
</head>
<body>
    <div class="actions">
        <button type="button" onclick="window.print()">Cetak / Simpan PDF</button>
    </div>
    <main class="receipt">
        <header>
            <h1>POS Bawang</h1>
            <div class="muted">Nota pembayaran</div>
        </header>
        <hr class="rule">
        <div class="meta"><span>No. nota</span><strong>{{ $transaction->invoice_no }}</strong></div>
        <div class="meta"><span>Waktu</span><span>{{ $transaction->occurred_at?->copy()->timezone('Asia/Jakarta')->format('d-m-Y H:i') }} WIB</span></div>
        <div class="meta"><span>Kasir</span><span>{{ $transaction->cashier?->name }}</span></div>
        @if ($transaction->customer_name)
            <div class="meta"><span>Pelanggan</span><span>{{ $transaction->customer_name }}</span></div>
        @endif
        <hr class="rule">
        <table>
            <thead><tr><th>Barang</th><th>Total</th></tr></thead>
            <tbody>
                @foreach ($transaction->items as $item)
                    @php
                        $weightParts = array_pad(explode('.', (string) $item->weight_kg, 2), 2, '');
                        $displayWeight = $weightParts[0].'.'.str_pad(substr($weightParts[1], 0, 3), 3, '0');
                    @endphp
                    <tr>
                        <td><span class="item-name">{{ $item->product_name }}</span><br><span class="muted">{{ $displayWeight }} kg × Rp{{ number_format($item->price_per_kg, 0, ',', '.') }}</span></td>
                        <td>Rp{{ number_format($item->line_total, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        <hr class="rule">
        <section class="totals">
            <div><span>Subtotal</span><span>Rp{{ number_format($transaction->subtotal, 0, ',', '.') }}</span></div>
            <div><span>Diskon</span><span>Rp{{ number_format($transaction->discount, 0, ',', '.') }}</span></div>
            <div class="grand-total"><span>Total</span><span>Rp{{ number_format($transaction->total, 0, ',', '.') }}</span></div>
            <div><span>Pembayaran</span><span>{{ strtoupper($transaction->payment_method) }}</span></div>
        </section>
        <p class="thanks">Terima kasih telah berbelanja.</p>
    </main>
</body>
</html>
