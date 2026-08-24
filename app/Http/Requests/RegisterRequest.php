<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $role = $this->input('role');
        $teamAction = $this->input('team_action');
        $isCreating = $teamAction === 'create';

        return [
            'username' => ['required', 'string', 'min:3', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['Coach', 'Player'])],
            'riot_id' => [Rule::requiredIf($role === 'Player'), Rule::prohibitedIf($role === 'Coach'), 'nullable', 'string', 'max:255', 'unique:users,riot_id'],
            // Team involvement is entirely optional at registration: omit both team_action and
            // team_code to register without a team and join one later.
            'team_action' => [Rule::prohibitedIf($role !== 'Coach'), 'nullable', Rule::in(['create'])],
            'team_name' => [Rule::requiredIf($isCreating), Rule::prohibitedIf(! $isCreating), 'nullable', 'string', 'max:255', 'unique:teams,team_name'],
            'description' => [Rule::prohibitedIf(! $isCreating), 'nullable', 'string'],
            'team_code' => [Rule::prohibitedIf($isCreating), 'nullable', 'string', 'max:32'],
        ];
    }
}
