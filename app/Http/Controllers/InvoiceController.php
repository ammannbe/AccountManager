<?php

namespace App\Http\Controllers;

use App\Billing\InvoicePdf;
use App\Enums\InvoiceStatus;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The user's own invoices in the portal. Other users' invoices and drafts answer 404.
 */
class InvoiceController extends Controller
{
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        return view('invoices.index', [
            'invoices' => $user->invoices()->issued()->orderByDesc('issued_at')->orderByDesc('id')->get(),
        ]);
    }

    public function download(Request $request, Invoice $invoice, InvoicePdf $pdf): StreamedResponse
    {
        abort_unless($invoice->user_id === $request->user()?->getKey() && $invoice->status !== InvoiceStatus::Draft, 404);

        return $pdf->download($invoice);
    }
}
