<?php

namespace App\Http\Requests;

use App\Models\TeamSettings;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

class UpdateTeamKeywordsRequest extends FormRequest
{
    private const CATEGORIES = ['informative_keywords', 'declarative_keywords'];

    private const MAX_PER_CATEGORY = 200;

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
        // Surrounding whitespace is stripped by the global TrimStrings
        // middleware before this runs, so the item rules see the trimmed
        // value: \S+ then rejects any entry with interior whitespace (and
        // any now-empty one).
        $item = ['string', 'max:64', 'regex:/^\S+$/'];

        return [
            'informative_keywords' => ['present', 'array', 'max:'.self::MAX_PER_CATEGORY],
            'informative_keywords.*' => $item,
            'declarative_keywords' => ['present', 'array', 'max:'.self::MAX_PER_CATEGORY],
            'declarative_keywords.*' => $item,
        ];
    }

    /**
     * Cross-cutting checks the per-field rules can't express: a category that
     * lists the same word twice (case-insensitively), and a word that appears
     * in both categories at once. Both fold case the same way the reconciler
     * does, so "A"/"a" and "smoke"/"Smoke" are caught here rather than sailing
     * through to a confusing partial write.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $folded = [];

            foreach (self::CATEGORIES as $field) {
                $words = $this->foldedWords($field);
                $folded[$field] = $words;

                if ($words->count() !== $words->unique()->count()) {
                    $validator->errors()->add($field, 'This category lists the same keyword more than once (case-insensitive).');
                }
            }

            $inBoth = $folded['informative_keywords']->intersect($folded['declarative_keywords'])->unique();

            if ($inBoth->isNotEmpty()) {
                $validator->errors()->add(
                    'declarative_keywords',
                    'These keywords appear in both categories: '.$inBoth->implode(', ').'.',
                );
            }
        });
    }

    /**
     * @return Collection<int, string>
     */
    private function foldedWords(string $field): Collection
    {
        return collect($this->input($field, []))
            ->filter(fn ($word): bool => is_string($word))
            ->map(fn (string $word): string => mb_strtolower(trim($word)))
            ->filter(fn (string $word): bool => $word !== '')
            ->values();
    }
}
