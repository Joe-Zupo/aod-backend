<?php

namespace App\Http\Requests;

use App\Models\GameEvent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class CompleteSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * `game_events` arrives in the multipart body either as a JSON string or as
     * an uploaded `.json` file (`docs/agents/game-event-ingest.md` produces a
     * file). Normalise both to a decoded array up front so the element rules
     * below can address it; an undecodable value is left as a string for the
     * `array` rule to reject. The uploaded file is dropped from the file bag so
     * `all()` does not re-overlay it over the decoded array during validation.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->game_events;

        if ($raw instanceof UploadedFile) {
            $this->files->remove('game_events');
            $raw = $raw->isValid() ? $raw->get() : '';
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);

            $this->merge([
                'game_events' => is_array($decoded) ? $decoded : $raw,
            ]);
        }
    }

    /**
     * One slot per recording player. Each is `players[N][user_id]` plus an
     * optional `players[N][audio]` and `players[N][video]` file, and a slot may
     * carry no file at all. The size ceilings are 200 MB for audio and 2 GB for
     * video, given here in kilobytes. Uploads that large also need PHP's
     * upload_max_filesize / post_max_size and the web server body limit raised
     * (see docs/adr/0003-session-recording-storage.md).
     *
     * `game_events` is validated all-or-nothing: any element that breaks the
     * schema fails the whole call with 422 and nothing is stored. Its
     * presence is enforced separately in the controller, before
     * `Session::complete()` (see docs/adr/0007-manual-game-event-ingest.md).
     */
    public function rules(): array
    {
        return [
            'players' => ['required', 'array', 'min:1'],
            'players.*.user_id' => ['required', 'integer', 'distinct', 'exists:users,id'],
            'players.*.audio' => [
                'nullable',
                'file',
                'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/ogg,audio/webm,audio/mp4',
                'max:204800',
            ],
            'players.*.video' => [
                'nullable',
                'file',
                'mimetypes:video/mp4,video/webm,video/quicktime,video/x-matroska',
                'max:2097152',
            ],
            'game_events' => ['nullable', 'array'],
            'game_events.*' => ['array'],
            'game_events.*.type' => ['required', Rule::in(GameEvent::TYPES)],
            'game_events.*.side' => [
                'required_unless:game_events.*.type,'.implode(',', GameEvent::ROUND_TYPES),
                'prohibited_if:game_events.*.type,'.implode(',', GameEvent::ROUND_TYPES),
                Rule::in(['ally', 'enemy']),
            ],
            'game_events.*.match_time_ms' => ['required', 'integer', 'min:0'],
            'game_events.*.round_number' => ['nullable', 'integer', 'min:1'],
            'game_events.*.note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
