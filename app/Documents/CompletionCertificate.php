<?php

namespace App\Documents;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A training certificate. It carries the company's name and logo without ever
 * naming them: both come from settings at render time.
 */
class CompletionCertificate extends BrandedDocument
{
    protected string $orientation = 'landscape';

    public function __construct(
        private readonly User $holder,
        private readonly string $courseTitle,
        private readonly string $reference,
        private readonly ?Carbon $issuedAt = null,
    ) {}

    public static function for(User $holder, string $courseTitle, ?string $reference = null): self
    {
        return new self(
            $holder,
            $courseTitle,
            $reference ?? 'CERT-'.Str::upper(Str::random(8)),
        );
    }

    protected function view(): string
    {
        return 'certificates.completion';
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(): array
    {
        return [
            'holderName' => $this->holder->displayName(),
            'courseTitle' => $this->courseTitle,
            'reference' => $this->reference,
            'issuedAt' => $this->issuedAt ?? now(),
        ];
    }

    public function filename(): string
    {
        return Str::slug($this->reference).'.pdf';
    }
}
