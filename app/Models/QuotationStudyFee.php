<?php

namespace App\Models;

use App\Enums\StudyFeeCreditStatus;
use App\Support\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The fee that turns an enquiry into a job.
 *
 * A row here is a permanent record, not a workflow step. Long after the request
 * is closed, somebody will ask whether this fee was credited against the
 * project — and the only defensible answer is a row with a name, a date and a
 * reason on it. That question is the reason this is a table and not three
 * columns on the request.
 */
class QuotationStudyFee extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'credit_status' => StudyFeeCreditStatus::class,
            'amount_kobo' => 'integer',
            'paid_at' => 'datetime',
            'credited_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $fee): void {
            $fee->credit_status ??= StudyFeeCreditStatus::Uncredited;
            $fee->currency ??= 'NGN';
        });
    }

    /**
     * @return BelongsTo<QuotationRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(QuotationRequest::class, 'quotation_request_id');
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * The administrator who decided what happened to this money.
     *
     * @return BelongsTo<User, $this>
     */
    public function creditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'credited_by');
    }

    public function isPaid(): bool
    {
        return $this->paid_at !== null;
    }

    public function amount(): string
    {
        return Money::fromKobo($this->amount_kobo);
    }

    /**
     * Whether anybody has yet made a decision about this fee.
     *
     * Distinct from `credit_status === Uncredited`: a fee nobody has looked at
     * and a fee somebody deliberately left uncredited are the same enum value
     * but different facts, and the timestamp is what separates them.
     */
    public function isDecided(): bool
    {
        return $this->credited_at !== null;
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopePaid(Builder $query): void
    {
        $query->whereNotNull('paid_at');
    }

    /**
     * @param  Builder<$this>  $query
     */
    public function scopeCredited(Builder $query): void
    {
        $query->where('credit_status', StudyFeeCreditStatus::Credited);
    }
}
