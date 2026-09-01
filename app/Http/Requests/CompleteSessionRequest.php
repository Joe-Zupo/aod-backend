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
     * Size ceilings are in kilobytes: 200 MB audio, 2 GB video. Uploads this
     * large also need PHP's upload_max_filesize / post_max_size and the web
     * server body limit raised (see docs/adr/0003-session-recording-storage.md).
     */
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'audio' => [
                'required',
                'file',
                'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/ogg,audio/webm,audio/mp4',
                'max:204800',
            ],
            'video' => [
                'required',
                'file',
                'mimetypes:video/mp4,video/webm,video/quicktime,video/x-matroska',
                'max:2097152',
            ],
        ];
    }
}
