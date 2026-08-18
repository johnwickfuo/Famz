<?php

namespace App\Documents;

use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A receipt rendered from the shared branded document layout. Present here so
 * the base layout has a second, non-certificate caller from the start.
 */
class OrderReceipt extends BrandedDocument
{
    /**
     * @param  array<int, array{description: string, quantity: int|float, unit_price: float}>  $lines
     */
    public function __construct(
        private readonly string $reference,
        private readonly string $buyerName,
        private readonly array $lines,
        private readonly ?Carbon $issuedAt = null,
    ) {}

    protected function view(): string
    {
        return 'pdf.receipt';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        $lines = collect($this->lines)
            ->map(fn (array $line): array => [
                ...$line,
                'total' => $line['quantity'] * $line['unit_price'],
            ])
            ->all();

        return [
            'reference' => $this->reference,
            'buyerName' => $this->buyerName,
            'lines' => $lines,
            'total' => collect($lines)->sum('total'),
            'issuedAt' => $this->issuedAt ?? now(),
        ];
    }

    public function filename(): string
    {
        return Str::slug($this->reference).'.pdf';
    }
}
