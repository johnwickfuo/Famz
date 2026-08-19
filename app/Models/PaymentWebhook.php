<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A webhook as it arrived, valid or not.
 *
 * Kept with its raw body because when money is disputed months later the
 * argument is settled by what the gateway actually sent, not by what the
 * application decided to record about it.
 */
class PaymentWebhook extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'signature_valid' => 'boolean',
            'processed_at' => 'datetime',
        ];
    }

    public function markProcessed(string $outcome, ?string $notes = null): void
    {
        $this->forceFill([
            'processed_at' => now(),
            'outcome' => $outcome,
            'notes' => $notes,
        ])->save();
    }
}
