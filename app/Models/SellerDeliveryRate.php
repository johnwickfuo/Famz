<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a seller charges to deliver to a given state.
 */
#[Fillable(['state', 'fee_kobo', 'is_active'])]
class SellerDeliveryRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'fee_kobo' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<SellerProfile, $this>
     */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(SellerProfile::class, 'seller_id');
    }
}
