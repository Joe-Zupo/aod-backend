<?php

namespace App\Http\Requests;

use App\Models\TeamSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTeamSettingsRequest extends FormRequest
{
    /**
     * Checked before validation runs, so an unauthorized caller is denied
     * regardless of whether their payload happens to be well-formed. Uses
     * Gate::authorize() rather than can() so a denyAsNotFound() policy
     * response still surfaces as 404, not a generic 403.
     */
    public function authorize(): bool
    {
        $team = $this->user()->resolveTeam($this);

        Gate::forUser($this->user())->authorize('update', TeamSettings::unsavedFor($team));

        return true;
    }

    public function rules(): array
    {
        return [
            'dead_air_threshold_ms' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'comm_event_padding_ms' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
            'game_alignment_window_ms' => ['sometimes', 'integer', 'min:1', 'max:2147483647'],
        ];
    }
}
