@php use App\Billing\Money; use App\Enums\InvoiceStatus; @endphp
<x-layout :title="__('Invoices')">
    <h1 class="mb-6 text-xl font-semibold">{{ __('Invoices') }}</h1>

    @if ($invoices->isEmpty())
        <p class="py-12 text-center text-stone-500">{{ __('There are no invoices yet.') }}</p>
    @else
        <ul class="rows">
            @foreach ($invoices as $invoice)
                <li class="flex items-center justify-between gap-3 p-4">
                    <div class="min-w-0">
                        <h2 class="font-semibold">{{ __('Invoice :number', ['number' => $invoice->number]) }}</h2>
                        <p class="mt-0.5 text-sm text-stone-600">
                            {{ Money::format($invoice->total_cents, $invoice->currency) }}
                            · {{ __('from :date', ['date' => $invoice->issued_at->format('d.m.Y')]) }}
                            @if ($invoice->status->isUnpaid())
                                · {{ __('payable by :date', ['date' => $invoice->due_at->format('d.m.Y')]) }}
                            @elseif ($invoice->status === InvoiceStatus::Paid && $invoice->paid_at)
                                · {{ __('paid on :date', ['date' => $invoice->paid_at->format('d.m.Y')]) }}
                            @endif
                        </p>
                        @if ($invoice->period_start)
                            <p class="text-sm text-stone-500">{{ __('Period') }}: {{ $invoice->period_start->format('d.m.Y') }}@if ($invoice->period_end) – {{ $invoice->period_end->format('d.m.Y') }}@endif</p>
                        @endif
                    </div>
                    <div class="flex shrink-0 flex-col items-end gap-2">
                        <span @class([
                            'badge',
                            'bg-green-100 text-green-800' => $invoice->status === InvoiceStatus::Paid,
                            'bg-amber-100 text-amber-900' => $invoice->status === InvoiceStatus::Open,
                            'bg-red-100 text-red-800' => $invoice->status === InvoiceStatus::Overdue,
                            'bg-stone-100 text-stone-600' => $invoice->status === InvoiceStatus::Cancelled,
                        ])>{{ $invoice->status->getLabel() }}</span>
                        <a href="{{ route('invoices.pdf', $invoice) }}" class="text-sm font-medium text-brand hover:underline">{{ __('PDF') }}</a>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif

    <p class="mt-6 text-sm text-stone-500">{{ __('The billing address is in your profile.') }} <a href="{{ route('profile') }}" class="underline">{{ __('Profile') }}</a></p>
</x-layout>
