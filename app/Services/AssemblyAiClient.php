<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Thin wrapper over AssemblyAI's pre-recorded transcription HTTP surface. No
 * SDK: three calls (upload a file, submit it, read it back) are all the
 * pipeline needs. Tuning lives in config/services.php under `assemblyai`.
 */
class AssemblyAiClient
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $baseUrl,
        private readonly string $speechModel,
    ) {}

    /**
     * Upload a local file and return the private `upload_url` AssemblyAI hands
     * back for it.
     */
    public function upload(string $absolutePath): string
    {
        return $this->request()
            ->withBody(file_get_contents($absolutePath), 'application/octet-stream')
            ->post('/v2/upload')
            ->throw()
            ->json('upload_url');
    }

    /**
     * Submit an uploaded file for transcription. Language is auto-detected and
     * constrained downstream; speaker labels are off because each AOD is one
     * player's mic.
     *
     * @param  string[]  $wordBoost
     * @return array<string, mixed> the created transcript resource
     */
    public function submitTranscript(string $audioUrl, array $wordBoost): array
    {
        return $this->request()
            ->post('/v2/transcript', [
                'audio_url' => $audioUrl,
                'speech_model' => $this->speechModel,
                'language_detection' => true,
                'speaker_labels' => false,
                'word_boost' => array_values($wordBoost),
            ])
            ->throw()
            ->json();
    }

    /**
     * Read one transcript back by its provider id.
     *
     * @return array<string, mixed>
     */
    public function getTranscript(string $providerTranscriptId): array
    {
        return $this->request()
            ->get('/v2/transcript/'.$providerTranscriptId)
            ->throw()
            ->json();
    }

    private function request(): PendingRequest
    {
        return Http::baseUrl($this->baseUrl)
            ->withHeaders(['Authorization' => $this->apiKey])
            ->acceptJson();
    }
}
