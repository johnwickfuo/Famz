<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProfileUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => [
                'required',
                'string',
                'lowercase',
                'email',
                'max:255',
                Rule::unique(User::class)->ignore($this->user()->id),
            ],

            'display_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'whatsapp' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', 'string', 'max:64'],
            'lga' => ['nullable', 'string', 'max:96'],
            'bio' => ['nullable', 'string', 'max:600'],
            'avatar' => ['nullable', 'image', 'max:2048'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function userAttributes(): array
    {
        return $this->safe()->only(['name', 'email']);
    }

    /**
     * @return array<string, mixed>
     */
    public function profileAttributes(): array
    {
        return $this->safe()->only(['display_name', 'phone', 'whatsapp', 'state', 'lga', 'bio']);
    }
}
