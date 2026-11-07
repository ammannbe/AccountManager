<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * One billed period of a subscription; description and price are snapshots of the plan.
 *
 * @property Carbon|null $period_start
 * @property Carbon|null $period_end
 */
class InvoiceLine extends Model
{
    protected $fillable = ['invoice_id', 'subscription_id', 'plan_id', 'position', 'description', 'period_start', 'period_end', 'quantity', 'unit_cents', 'amount_cents'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Invoice lines are kept with their invoice and never deleted.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'period_start' => 'date',
            'period_end' => 'date',
            'quantity' => 'integer',
            'unit_cents' => 'integer',
            'amount_cents' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /**
     * @return BelongsTo<Subscription, $this>
     */
    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
