<?php

namespace App\Services\Mentorship;

use App\Models\User;

/**
 * What a client said they need.
 *
 * A value object rather than an array so the matcher's signature says what it
 * takes, and so the same shape can be stored, replayed and compared when
 * somebody tunes the ranking later.
 */
final class MatchRequest
{
    /**
     * @param  array<int, int>  $specialisationIds  Tags the client picked themselves, if any.
     */
    public function __construct(
        public readonly string $need,
        public readonly ?string $sector = null,
        public readonly ?string $state = null,
        public readonly bool $wantsRemote = true,
        public readonly bool $wantsInPerson = false,
        public readonly ?int $budgetMinKobo = null,
        public readonly ?int $budgetMaxKobo = null,
        public readonly array $specialisationIds = [],
        public readonly ?User $user = null,
        public readonly ?string $sessionToken = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, ?User $user = null, ?string $sessionToken = null): self
    {
        return new self(
            need: trim((string) ($data['need'] ?? '')),
            sector: $data['sector'] ?? null,
            state: $data['state'] ?? null,
            wantsRemote: (bool) ($data['wants_remote'] ?? true),
            wantsInPerson: (bool) ($data['wants_in_person'] ?? false),
            budgetMinKobo: isset($data['budget_min_kobo']) ? (int) $data['budget_min_kobo'] : null,
            budgetMaxKobo: isset($data['budget_max_kobo']) ? (int) $data['budget_max_kobo'] : null,
            specialisationIds: array_map('intval', $data['specialisations'] ?? []),
            user: $user,
            sessionToken: $sessionToken,
        );
    }

    public function hasBudget(): bool
    {
        return $this->budgetMinKobo !== null || $this->budgetMaxKobo !== null;
    }

    /**
     * Whether a price falls inside what they said they could pay.
     */
    public function affords(int $priceKobo): bool
    {
        if ($this->budgetMaxKobo !== null && $priceKobo > $this->budgetMaxKobo) {
            return false;
        }

        return ! ($this->budgetMinKobo !== null && $priceKobo < $this->budgetMinKobo);
    }
}
