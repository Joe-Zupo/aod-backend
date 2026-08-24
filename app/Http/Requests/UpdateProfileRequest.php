<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $userId = $this->user()->id;

        return [
            'username' => ['sometimes', 'string', 'min:3', 'max:255', Rule::unique('users')->ignore($userId)],
            'email' => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($userId)],
            'riot_id' => ['sometimes', 'nullable', 'string', 'max:255', Rule::unique('users')->ignore($userId)],
            'role' => ['prohibited'],
            'member_role' => ['prohibited'],
        ];
    }
}
