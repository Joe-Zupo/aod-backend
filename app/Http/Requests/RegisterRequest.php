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
        $requiresTeamChoice = in_array($role, ['Coach', 'Team Leader'], true);
        $requiresRiotId = in_array($role, ['Player', 'Team Leader'], true);
        $requiresTeamCode = $role === 'Player' || ($requiresTeamChoice && $teamAction === 'join');

        return [
            'username' => ['required', 'string', 'min:3', 'max:255', 'unique:users,username'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'role' => ['required', 'string', Rule::in(['Coach', 'Team Leader', 'Player'])],
            'riot_id' => [Rule::requiredIf($requiresRiotId), Rule::prohibitedIf($role === 'Coach'), 'nullable', 'string', 'max:255', 'unique:users,riot_id'],
            'team_action' => [Rule::requiredIf($requiresTeamChoice), 'nullable', Rule::in(['create', 'join'])],
            'team_name' => [Rule::requiredIf($requiresTeamChoice && $teamAction === 'create'), 'nullable', 'string', 'max:255', 'unique:teams,team_name'],
            'team_code' => [Rule::requiredIf($requiresTeamCode), Rule::prohibitedIf($requiresTeamChoice && $teamAction === 'create'), 'nullable', 'string', 'max:32'],
        ];
    }
}
