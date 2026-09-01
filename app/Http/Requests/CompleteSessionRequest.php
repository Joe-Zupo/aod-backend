<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CompleteSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * One slot per recording player. Each is `players[N][user_id]` plus an
     * optional `players[N][audio]` and `players[N][video]` file, and a slot may
     * carry no file at all. The size ceilings are 200 MB for audio and 2 GB for
     * video, given here in kilobytes. Uploads that large also need PHP's
     * upload_max_filesize / post_max_size and the web server body limit raised
     * (see docs/adr/0003-session-recording-storage.md).
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
        ];
    }
}
