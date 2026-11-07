<?php

namespace App\Billing;

use App\Models\Invoice;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;
use Sprain\SwissQrBill\PaymentPart\Translation\Translation;
use Sprain\SwissQrBill\QrCode\QrCode;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The PDF of an invoice (dompdf, A4) with the Swiss QR-bill payment part at the bottom. Stored on
 * the private local disk (storage/app/private/invoices/<year>/), never public; rendered again from
 * the invoice's snapshots if the file is missing.
 */
class InvoicePdf
{
    public const DISK = 'local';

    /**
     * Above this many lines the payment part gets its own page.
     */
    private const LINES_ON_FIRST_PAGE = 6;

    public function __construct(private QrBills $qrBills) {}

    public function render(Invoice $invoice): string
    {
        $invoice->loadMissing('lines');
        $bill = $this->qrBills->forInvoice($invoice);
        $language = $this->language();

        $html = view('invoices.pdf', [
            'invoice' => $invoice,
            'bill' => $bill,
            'qrCode' => $bill?->getQrCode(QrCode::FILE_FORMAT_PNG)->getDataUri(QrCode::FILE_FORMAT_PNG),
            'texts' => Translation::getAllByLanguage($language) ?? [],
            'marks' => fn (string $name): string => 'data:image/png;base64,'.base64_encode((string) file_get_contents(base_path("vendor/sprain/swiss-qr-bill/assets/marks_{$name}.png"))),
            'separatePage' => $invoice->lines->count() > self::LINES_ON_FIRST_PAGE,
        ])->render();

        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setIsPhpEnabled(false);
        $options->setDefaultFont('Helvetica');
        $options->setChroot([resource_path()]);
        $options->setTempDir(sys_get_temp_dir());
        // Font metrics are cached here instead of in vendor/ (writable, survives composer install).
        $options->setFontCache($this->fontCache());

        $dompdf = new Dompdf($options);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->addInfo('Title', __('Invoice :number', ['number' => $invoice->number]));
        $dompdf->addInfo('Author', $invoice->creditor['name']);
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Renders and stores the PDF; returns its path on the disk.
     */
    public function store(Invoice $invoice): string
    {
        $path = "invoices/{$invoice->year}/".preg_replace('/[^A-Za-z0-9_-]+/', '-', $invoice->number).'.pdf';
        Storage::disk(self::DISK)->put($path, $this->render($invoice));

        if ($invoice->pdf_path !== $path) {
            $invoice->update(['pdf_path' => $path]);
        }

        return $path;
    }

    /**
     * Path of the stored PDF, rendered first if it is missing.
     */
    public function ensure(Invoice $invoice): string
    {
        if ($invoice->pdf_path !== null && Storage::disk(self::DISK)->exists($invoice->pdf_path)) {
            return $invoice->pdf_path;
        }

        return $this->store($invoice);
    }

    public function contents(Invoice $invoice): string
    {
        return (string) Storage::disk(self::DISK)->get($this->ensure($invoice));
    }

    public function download(Invoice $invoice): StreamedResponse
    {
        return Storage::disk(self::DISK)->download($this->ensure($invoice), $invoice->fileName(), ['Content-Type' => 'application/pdf']);
    }

    private function fontCache(): string
    {
        $directory = storage_path('framework/cache/dompdf');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        return $directory;
    }

    /**
     * Language of the payment part texts (de, fr, it, en).
     */
    private function language(): string
    {
        $language = substr(app()->getLocale(), 0, 2);

        return in_array($language, ['de', 'fr', 'it', 'en'], true) ? $language : 'en';
    }
}
