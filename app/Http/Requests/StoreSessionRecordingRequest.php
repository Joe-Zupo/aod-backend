<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One participant's own audio and/or video for a session they are actively
 * recording in. At least one of the two is required; either may be omitted
 * (a player whose screen capture failed can still deliver their mic). Same
 * MIME/size ceilings as the old completion call
 * (docs/adr/0003-session-recording-storage.md): 200 MB audio, 2 GB video.
 */
class StoreSessionRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'audio' => [
                'required_without:video',
                'nullable',
                'file',
                'mimetypes:audio/mpeg,audio/wav,audio/x-wav,audio/aac,audio/ogg,audio/webm,audio/mp4',
                'max:204800',
            ],
            'video' => [
                'required_without:audio',
                'nullable',
                'file',
                'mimetypes:video/mp4,video/webm,video/quicktime,video/x-matroska',
                'max:2097152',
            ],
            'audio_client_started_at' => ['nullable', 'date'],
            'video_client_started_at' => ['nullable', 'date'],
        ];
    }
}
