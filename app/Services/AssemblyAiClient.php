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
    ) {}

    /**
     * Upload a local file and return the private `upload_url` AssemblyAI hands
     * back for it. Streamed from disk rather than read whole into memory, since
     * a recording can be hundreds of MB.
     */
    public function upload(string $absolutePath): string
    {
        $handle = fopen($absolutePath, 'rb');

        try {
            return $this->request()
                ->timeout(300)
                ->withBody($handle, 'application/octet-stream')
                ->post('/v2/upload')
                ->throw()
                ->json('upload_url');
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
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
            ->asJson()
            ->post('/v2/transcript', [
                'audio_url' => $audioUrl,
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
