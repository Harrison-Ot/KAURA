<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $sale['reference'] }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 20px; font: 12px/1.5 system-ui, sans-serif; color: #111; background: #eee; }
        .receipt { width: 80mm; max-width: 100%; margin: auto; background: white; padding: 5mm; }
        h1 { font-size: 18px; margin: 0; } h2 { font-size: 14px; } p { margin: 4px 0; overflow-wrap: anywhere; }
        header { text-align: center; border-bottom: 1px dashed #777; padding-bottom: 10px; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 12px 0; }
        th, td { padding: 4px 2px; text-align: right; vertical-align: top; overflow-wrap: anywhere; }
        th:first-child, td:first-child { text-align: left; width: 40%; }
        th { border-bottom: 1px solid #777; } .total { font-weight: bold; border-top: 1px solid #777; padding-top: 8px; }
        .row { display: flex; justify-content: space-between; gap: 8px; } small { display: block; }
        .actions { max-width: 80mm; margin: 0 auto 12px; display: flex; justify-content: space-between; }
        @media print { @page { margin: 8mm; } body { background: white; padding: 0; } .actions { display: none; } .receipt { padding: 0; margin: 0; } tr { break-inside: avoid; } }
    </style>
</head>
<body>
<nav class="actions" aria-label="Receipt actions"><a href="{{ route('sales.show', $sale['id']) }}">Back to sale</a><button type="button" onclick="window.print()">Print receipt</button></nav>
<main class="receipt">
    <header><h1>{{ $sale['shop_snapshot']['shop_name'] }}</h1>
        @if($sale['shop_snapshot']['address'])<p>{{ $sale['shop_snapshot']['address'] }}</p>@endif
        @if($sale['shop_snapshot']['contact'])<p>{{ $sale['shop_snapshot']['contact'] }}</p>@endif
        <h2>Sales receipt</h2><p>{{ $sale['reference'] }}</p>
    </header>
    <p>{{ \Carbon\CarbonImmutable::parse($sale['sold_at'])->setTimezone('Africa/Nairobi')->format('d M Y H:i') }} · Nairobi</p>
    <p>Sold by: {{ $sale['employee_name'] }}</p>
    <p>Customer: {{ $sale['customer_snapshot']['name'] ?? 'Walk-in customer' }}</p>
    <table><caption>All prices in KES</caption><thead><tr><th>Item</th><th>Qty</th><th>Unit price</th><th>Total</th></tr></thead><tbody>
    @foreach($sale['items'] as $item)
        <tr><td>{{ $item['name'] }}<small>{{ $item['sku'] }} · {{ $item['unit_label'] }}</small></td><td>{{ rtrim(rtrim($item['quantity'], '0'), '.') }}</td><td>{{ $item['unit_price'] }}</td><td>{{ $item['total'] }}</td></tr>
    @endforeach
    </tbody></table>
    @if($sale['discount'] !== '0.00')<p class="row"><span>Gross KES</span><span>{{ $sale['gross'] }}</span></p><p class="row"><span>Approved discount</span><span>{{ $sale['discount'] }}</span></p>@endif
    <p class="row total"><span>Total KES</span><span>{{ $sale['total'] }}</span></p>
    @if($sale['promised_due_on'])<p>Approved credit: KES {{ $sale['approved_credit'] }} · Promised date {{ $sale['promised_due_on'] }}</p>@endif
    @if($sale['checkout_method'] === 'cash')
        <p class="row"><span>Cash tendered</span><span>{{ $sale['cash_tendered'] }}</span></p>
        <p class="row"><span>Change</span><span>{{ $sale['change'] }}</span></p>
    @endif
    @foreach($sale['payments'] as $payment)
        <p class="row"><span>{{ $payment['method'] === 'cash' ? 'Cash' : 'Manual M-Pesa' }} · {{ $payment['status'] === 'verified' ? 'confirmed' : $payment['status'] }}</span><span>{{ $payment['amount'] }}</span></p>
        @if($payment['mpesa_code'])<p>Code: {{ $payment['mpesa_code'] }} · Receiving number: {{ $payment['receiving_number'] }}</p>@endif
    @endforeach
    <p class="row"><span>Confirmed collections</span><span>{{ $sale['settlement']['confirmed'] }}</span></p>
    <p class="row"><span>Balance due</span><span>{{ $sale['settlement']['balance_due'] }}</span></p>
    @if($sale['settlement']['state'] === 'voided')<p>VOIDED — original sale retained for history.</p><p>Reason: {{ $sale['void']['reason'] ?? '' }}</p><p>Stock review: {{ $sale['void']['stock_status'] ?? 'pending' }}</p>
        @if($sale['void']['resolution'] ?? null)<p>{{ $sale['void']['resolution']['kind'] === 'refund' ? 'Owner refund recorded' : 'Retained customer credit recorded' }}: KES {{ $sale['void']['resolution']['amount'] }} · {{ $sale['void']['resolution']['business_date'] }}</p>
            @if($sale['void']['resolution']['credit_payout_amount'] ?? null)<p>Retained credit later paid out: KES {{ $sale['void']['resolution']['credit_payout_amount'] }}</p>@endif
        @else<p>No refund is confirmed by this receipt.</p><p>Confirmed funds requiring owner follow-up: KES {{ $sale['void']['confirmed_funds'] ?? '0.00' }}</p>@endif
    @elseif($sale['settlement']['state'] === 'paid')<p>Paid in full · Thank you</p>
    @elseif($sale['settlement']['state'] === 'disputed')<p>Payment disputed · Balance unresolved. Contact the shop.</p>
    @elseif($sale['settlement']['state'] === 'credit_due')<p>Approved credit balance remains due by {{ $sale['promised_due_on'] }}.</p>
    @else<p>M-Pesa pending owner verification. This receipt does not confirm receipt of M-Pesa funds.</p>@endif
</main>
</body>
</html>
