<?php

namespace App\Http\Requests;

use App\Models\TeamSettings;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class UpdateTeamKeywordsRequest extends FormRequest
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

        Gate::forUser($this->user())->authorize('updateKeywords', TeamSettings::unsavedFor($team));

        return true;
    }

    public function rules(): array
    {
        return [
            'informative_keywords' => ['present', 'array'],
            'informative_keywords.*' => ['string'],
            'declarative_keywords' => ['present', 'array'],
            'declarative_keywords.*' => ['string'],
        ];
    }
}
