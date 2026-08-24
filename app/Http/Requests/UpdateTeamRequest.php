<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeamRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $teamId = $this->user()->resolveTeam($this)->id;

        return [
            'team_name' => ['sometimes', 'string', 'max:255', Rule::unique('teams')->ignore($teamId)],
            'description' => ['sometimes', 'nullable', 'string'],
            'team_code' => ['prohibited'],
        ];
    }
}
