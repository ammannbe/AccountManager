{{--
    Swiss QR-bill payment part (Swiss Implementation Guidelines, style guide): 210 × 105 mm at the
    bottom of the A4 page (the offsets are relative to the page margins of pdf.blade.php), receipt
    62 mm and payment part 148 mm, dashed separation lines with
    scissors (no perforated paper). Helvetica, headings bold 6/8 pt, values 8/10 pt.
--}}
@php
    $debtor = $bill->getUltimateDebtor();
    $reference = $bill->getPaymentReference()->getFormattedReference();
    $additional = $bill->getAdditionalInformation()?->getFormattedString();
    $amount = $bill->getPaymentAmountInformation()->getFormattedAmount();
    $currency = $bill->getPaymentAmountInformation()->getCurrency();
    $iban = $bill->getCreditorInformation()->getFormattedIban();
    $lines = fn (string $text): string => nl2br(e($text), false);
@endphp
<style>
    .qr-page-break { page-break-before: always; }
    .qr { position: absolute; top: 177mm; left: -20mm; width: 210mm; height: 105mm; border-top: 0.2mm dashed #000; font-family: Helvetica, Arial, sans-serif; color: #000; }
    .qr div { position: absolute; }
    .qr .scissors { font-family: 'DejaVu Sans', sans-serif; font-size: 11pt; line-height: 1; }
    .qr .receipt { top: 0; left: 0; width: 62mm; height: 105mm; border-right: 0.2mm dashed #000; }
    .qr h1 { font-size: 11pt; line-height: 13pt; font-weight: bold; margin: 0; }
    .qr h2 { font-weight: bold; margin: 0; }
    .qr p { margin: 0; }
    .qr .rc h2 { font-size: 6pt; line-height: 9pt; }
    .qr .rc p { font-size: 8pt; line-height: 9pt; margin-bottom: 9pt; }
    .qr .part h2 { font-size: 8pt; line-height: 11pt; }
    .qr .part p { font-size: 10pt; line-height: 11pt; margin-bottom: 11pt; }
    .qr img.code { position: absolute; top: 17mm; left: 67mm; width: 46mm; height: 46mm; }
</style>

@if ($separatePage)
    <div class="qr-page-break"></div>
@endif

<div class="qr">
    <div class="scissors" style="top: -2.3mm; left: 4mm;">&#9986;</div>
    <div class="scissors" style="top: 4mm; left: 59.7mm;">&#9986;</div>

    {{-- Receipt --}}
    <div class="receipt">
        <div class="rc" style="top: 5mm; left: 5mm; width: 52mm;"><h1>{{ $texts['receipt'] }}</h1></div>
        <div class="rc" style="top: 12mm; left: 5mm; width: 52mm; height: 56mm;">
            <h2>{{ $texts['creditor'] }}</h2>
            <p>{{ $iban }}<br>{!! $lines($bill->getCreditor()->getFullAddress(true)) !!}</p>
            @if ($reference)
                <h2>{{ $texts['reference'] }}</h2>
                <p>{{ $reference }}</p>
            @endif
            @if ($debtor)
                <h2>{{ $texts['payableBy'] }}</h2>
                <p>{!! $lines($debtor->getFullAddress(true)) !!}</p>
            @else
                <h2>{{ $texts['payableByName'] }}</h2>
                <img src="{{ $marks('52x20mm') }}" style="width: 52mm; height: 20mm; margin-top: 1pt;" alt="">
            @endif
        </div>
        <div class="rc" style="top: 68mm; left: 5mm; width: 52mm; height: 14mm;">
            <div style="top: 0; left: 0; width: 13mm;"><h2>{{ $texts['currency'] }}</h2><p>{{ $currency }}</p></div>
            <div style="top: 0; left: 13mm; width: 39mm;"><h2>{{ $texts['amount'] }}</h2><p>{{ $amount }}</p></div>
        </div>
        <div class="rc" style="top: 82mm; left: 5mm; width: 52mm; height: 18mm; text-align: right;">
            <h2>{{ $texts['acceptancePoint'] }}</h2>
        </div>
    </div>

    {{-- Payment part --}}
    <div class="part" style="top: 5mm; left: 67mm; width: 46mm;"><h1>{{ $texts['paymentPart'] }}</h1></div>
    <img class="code" src="{{ $qrCode }}" alt="">
    <div class="part" style="top: 68mm; left: 67mm; width: 51mm; height: 22mm;">
        <div style="top: 0; left: 0; width: 14mm;"><h2>{{ $texts['currency'] }}</h2><p>{{ $currency }}</p></div>
        <div style="top: 0; left: 14mm; width: 37mm;"><h2>{{ $texts['amount'] }}</h2><p>{{ $amount }}</p></div>
    </div>
    <div class="part" style="top: 5mm; left: 118mm; width: 87mm; height: 85mm;">
        <h2>{{ $texts['creditor'] }}</h2>
        <p>{{ $iban }}<br>{!! $lines($bill->getCreditor()->getFullAddress()) !!}</p>
        @if ($reference)
            <h2>{{ $texts['reference'] }}</h2>
            <p>{{ $reference }}</p>
        @endif
        @if ($additional)
            <h2>{{ $texts['additionalInformation'] }}</h2>
            <p>{!! $lines($additional) !!}</p>
        @endif
        @if ($debtor)
            <h2>{{ $texts['payableBy'] }}</h2>
            <p>{!! $lines($debtor->getFullAddress()) !!}</p>
        @else
            <h2>{{ $texts['payableByName'] }}</h2>
            <img src="{{ $marks('65x25mm') }}" style="width: 65mm; height: 25mm; margin-top: 1pt;" alt="">
        @endif
    </div>
</div>
