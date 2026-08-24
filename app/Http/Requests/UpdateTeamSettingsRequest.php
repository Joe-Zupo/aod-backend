<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTeamSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dead_air_threshold_ms' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
