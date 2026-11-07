@php
    use App\Billing\Money;
    $creditor = $invoice->creditor;
    $recipient = $invoice->recipient;
    $date = fn ($value) => $value?->format('d.m.Y');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('Invoice :number', ['number' => $invoice->number]) }}</title>
    <style>
        /* The content flows (long invoices continue on the next pages); header and payment part are
           positioned relative to the page margins. */
        @page { margin: 15mm 20mm 20mm 20mm; }
        body { margin: 0; font-family: Helvetica, Arial, sans-serif; font-size: 10pt; line-height: 1.3; color: #000; }
        .abs { position: absolute; }
        .muted { color: #555; }
        .creditor { top: 0; left: 0; width: 90mm; font-size: 9pt; }
        .creditor strong { font-size: 11pt; }
        /* Address window of a C5/C6 envelope (right). */
        .recipient { top: 33mm; left: 98mm; width: 72mm; }
        .content { margin-top: 65mm; }
        h1 { font-size: 15pt; margin: 0 0 3mm; }
        table { border-collapse: collapse; width: 100%; }
        .meta td { padding: 0.3mm 0; vertical-align: top; }
        .meta td:first-child { width: 40mm; color: #555; }
        .lines { margin-top: 5mm; }
        .lines th { text-align: left; font-size: 9pt; border-bottom: 0.3mm solid #000; padding: 1mm 0; }
        .lines td { padding: 0.8mm 0; border-bottom: 0.1mm solid #bbb; vertical-align: top; }
        .lines .amount { text-align: right; white-space: nowrap; }
        .lines tfoot td { border-bottom: none; border-top: 0.3mm solid #000; font-weight: bold; padding-top: 1.5mm; }
        .note { margin: 4mm 0 0; }
    </style>
</head>
<body>
    <div class="abs creditor">
        <strong>{{ $creditor['name'] }}</strong><br>
        @if ($creditor['street']){{ $creditor['street'] }}<br>@endif
        {{ $creditor['postal_code'] }} {{ $creditor['city'] }}@if ($creditor['country'] && $creditor['country'] !== 'CH'), {{ $creditor['country'] }}@endif<br>
        @if ($creditor['email']){{ $creditor['email'] }}<br>@endif
        @if ($creditor['website']){{ $creditor['website'] }}<br>@endif
        @if ($creditor['vat_number']){{ __('VAT no.') }} {{ $creditor['vat_number'] }}@endif
    </div>

    <div class="abs recipient">
        {{ $recipient['name'] }}<br>
        @if ($recipient['street']){{ $recipient['street'] }}<br>@endif
        @if ($recipient['postal_code'] || $recipient['city']){{ $recipient['postal_code'] }} {{ $recipient['city'] }}<br>@endif
        @if ($recipient['country'] && $recipient['country'] !== $creditor['country']){{ \Symfony\Component\Intl\Countries::getName($recipient['country'], app()->getLocale()) }}<br>@endif
        @if (! $recipient['city'] && $recipient['email']){{ $recipient['email'] }}@endif
    </div>

    <div class="content">
        <h1>{{ __('Invoice :number', ['number' => $invoice->number]) }}</h1>

        <table class="meta">
            <tr><td>{{ __('Invoice date') }}</td><td>{{ $date($invoice->issued_at) }}</td></tr>
            <tr><td>{{ __('Payable by') }}</td><td>{{ $date($invoice->due_at) }}</td></tr>
            @if ($invoice->period_start)
                <tr><td>{{ __('Period') }}</td><td>{{ $date($invoice->period_start) }}@if ($invoice->period_end) – {{ $date($invoice->period_end) }}@endif</td></tr>
            @endif
            @if ($invoice->reference && $bill)
                <tr><td>{{ __('Reference') }}</td><td>{{ $bill->getPaymentReference()->getFormattedReference() }}</td></tr>
            @endif
        </table>

        <table class="lines">
            <thead>
                <tr><th>{{ __('Description') }}</th><th style="width: 48mm;">{{ __('Period') }}</th><th class="amount" style="width: 28mm;">{{ __('Amount') }} {{ $invoice->currency }}</th></tr>
            </thead>
            <tbody>
                @foreach ($invoice->lines as $line)
                    <tr>
                        <td>{{ $line->description }}@if ($line->quantity > 1) ({{ $line->quantity }} × {{ Money::amount($line->unit_cents) }})@endif</td>
                        <td>@if ($line->period_start){{ $date($line->period_start) }}@if ($line->period_end) – {{ $date($line->period_end) }}@endif @else – @endif</td>
                        <td class="amount">{{ Money::amount($line->amount_cents) }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot>
                <tr><td colspan="2">{{ __('Total') }}</td><td class="amount">{{ Money::format($invoice->total_cents, $invoice->currency) }}</td></tr>
            </tfoot>
        </table>

        <p class="note">
            @if ($bill)
                {{ __('Please pay the amount by :date with the payment slip below.', ['date' => $date($invoice->due_at)]) }}
            @else
                {{ __('Please pay the amount by :date to IBAN :iban (:name), reference :number.', ['date' => $date($invoice->due_at), 'iban' => trim(chunk_split($creditor['iban'], 4, ' ')), 'name' => $creditor['name'], 'number' => $invoice->number]) }}
            @endif
            @if ($creditor['vat_note'])
                <br><span class="muted">{{ $creditor['vat_note'] }}</span>
            @endif
        </p>
    </div>

    @if ($bill)
        @include('invoices.payment-part')
    @endif
</body>
</html>
