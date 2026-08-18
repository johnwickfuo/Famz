<?php

namespace App\Http\Requests;

use App\Enums\BusinessType;
use App\Models\Category;
use App\Models\SellerProfile;
use App\Support\Nigeria;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SellerApplicationRequest extends FormRequest
{
    private ?SellerProfile $seller = null;

    private bool $sellerResolved = false;

    public function authorize(): bool
    {
        $existing = $this->existingProfile();

        // A first application, or an edit to one that is still open. A rejected
        // or approved application is not re-openable from here.
        return $existing === null || $existing->status->isOpenToApplicant();
    }

    /**
     * The applicant's existing profile, read straight from the database.
     *
     * Deliberately not $user->sellerProfile: a relation loaded earlier in the
     * request lifecycle stays cached, so a profile created since would be
     * invisible here and the applicant would be asked for their ID document a
     * second time.
     */
    public function existingProfile(): ?SellerProfile
    {
        if (! $this->sellerResolved) {
            $this->seller = SellerProfile::query()
                ->where('user_id', $this->user()?->getKey())
                ->first();

            $this->sellerResolved = true;
        }

        return $this->seller;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasDocument = filled($this->existingProfile()?->id_document);

        return [
            'business_name' => ['required', 'string', 'min:2', 'max:160'],

            // Optional on purpose: most traders in this market are not CAC
            // registered, and requiring it would shut them out.
            'cac_number' => ['nullable', 'string', 'max:32'],

            'business_type' => ['required', Rule::enum(BusinessType::class)],
            'address' => ['required', 'string', 'max:400'],
            'state' => ['required', 'string', Rule::in(Nigeria::states())],
            'lga' => ['required', 'string', 'max:96'],
            'phone' => ['required', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],

            // Required the first time; on a resubmission the stored document
            // stands unless a new one is uploaded.
            'id_document' => [$hasDocument ? 'nullable' : 'required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],

            'description' => ['required', 'string', 'min:40', 'max:2000'],

            'categories' => ['required', 'array', 'min:1', 'max:12'],
            'categories.*' => ['integer', Rule::exists(Category::class, 'id')->where('is_active', true)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'categories.required' => __('Choose at least one category you intend to sell in.'),
            'description.min' => __('Tell buyers a little more — at least a sentence or two about what you sell.'),
            'id_document.required' => __('Please upload a photograph of your ID or business registration.'),
            'state.in' => __('Choose a state from the list.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->safe()->only([
            'business_name',
            'cac_number',
            'business_type',
            'address',
            'state',
            'lga',
            'phone',
            'whatsapp',
            'description',
        ]);
    }

    /**
     * @return array<int, int>
     */
    public function categoryIds(): array
    {
        return array_map('intval', $this->validated('categories', []));
    }
}
