<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use App\Enums\ReferenceType;
use Carbon\CarbonInterface;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * An invoice of subscription periods. Kept for 10 years (OR), also after the user is deleted:
 * never deleted, only cancelled. Recipient and creditor are snapshots of the time of the invoice,
 * so the PDF can always be rendered again exactly the same.
 *
 * @property InvoiceStatus $status
 * @property ReferenceType $reference_type
 * @property array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, email: ?string} $recipient
 * @property array{name: string, street: ?string, postal_code: ?string, city: ?string, country: ?string, iban: string, email: ?string, website: ?string, vat_number: ?string, vat_note: ?string} $creditor
 * @property Carbon $issued_at
 * @property Carbon $due_at
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 * @property Carbon|null $paid_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $overdue_at
 * @property Carbon|null $reminded_at
 * @property Carbon|null $cancelled_at
 */
class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected $fillable = [
        'number', 'year', 'sequence', 'user_id', 'recipient', 'creditor', 'currency', 'total_cents', 'status',
        'period_start', 'period_end', 'issued_at', 'due_at', 'reference_type', 'reference', 'pdf_path',
        'sent_at', 'overdue_at', 'reminded_at', 'paid_at', 'payment_note', 'paid_by', 'cancelled_at', 'cancel_reason', 'cancelled_by',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Invoices are kept for 10 years and never deleted; cancel them instead.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'reference_type' => ReferenceType::class,
            'recipient' => 'array',
            'creditor' => 'array',
            'year' => 'integer',
            'sequence' => 'integer',
            'total_cents' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'issued_at' => 'date',
            'due_at' => 'date',
            'paid_at' => 'date',
            'sent_at' => 'datetime',
            'overdue_at' => 'datetime',
            'reminded_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<InvoiceLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(InvoiceLine::class)->orderBy('position');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by');
    }

    /**
     * Issued invoices (what the user sees).
     *
     * @param  Builder<Invoice>  $query
     * @return Builder<Invoice>
     */
    public function scopeIssued(Builder $query): Builder
    {
        return $query->where('status', '!=', InvoiceStatus::Draft);
    }

    public function canBeMarkedPaid(): bool
    {
        return $this->status->isUnpaid();
    }

    public function canBeCancelled(): bool
    {
        return $this->status === InvoiceStatus::Draft || $this->status->isUnpaid();
    }

    public function markPaid(CarbonInterface $date, ?string $note = null, ?User $by = null): void
    {
        if (! $this->canBeMarkedPaid()) {
            throw new LogicException("Invoice {$this->number} cannot be marked as paid.");
        }

        $this->update([
            'status' => InvoiceStatus::Paid,
            'paid_at' => $date,
            'payment_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            'paid_by' => $by?->id,
        ]);
    }

    /**
     * The billed periods stay billed (they are not invoiced again).
     */
    public function cancel(string $reason, ?User $by = null): void
    {
        if (! $this->canBeCancelled()) {
            throw new LogicException("Invoice {$this->number} cannot be cancelled.");
        }

        $this->update([
            'status' => InvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => trim($reason),
            'cancelled_by' => $by?->id,
        ]);
    }

    public function fileName(): string
    {
        return __('Invoice').'-'.preg_replace('/[^A-Za-z0-9_-]+/', '-', $this->number).'.pdf';
    }
}
