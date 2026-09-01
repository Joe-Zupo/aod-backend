<?php

namespace Tests\Feature;

use App\Jobs\PollTranscription;
use App\Jobs\SubmitTranscription;
use App\Models\Transcript;
use App\Services\AssemblyAiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

/**
 * The terminal failure conditions for a transcript: the provider reports an
 * error, the detected language is one we do not accept, the poll never resolves,
 * or a job exhausts its retries. Each leaves the transcript `failed` with a
 * reason and stops the pipeline for that AOD only.
 */
class SessionTranscriptionFailureTest extends TestCase
{
    use RefreshDatabase;

    private function poll(Transcript $transcript): void
    {
        (new PollTranscription($transcript))->handle(app(AssemblyAiClient::class));
    }

    public function test_poll_fails_the_transcript_on_a_provider_error(): void
    {
        Http::fake(['*/v2/transcript/prov_err' => Http::response([
            'status' => 'error',
            'error' => 'Download error to https://cdn.assemblyai.com/x failed',
        ])]);
        Bus::fake();

        $transcript = Transcript::factory()->processing('prov_err')->create();

        $this->poll($transcript);

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertStringContainsString('Download error', $transcript->error);
        Bus::assertNotDispatched(PollTranscription::class);
    }

    public function test_poll_fails_the_transcript_when_the_detected_language_is_not_accepted(): void
    {
        Http::fake(['*/v2/transcript/prov_lang' => Http::response([
            'status' => 'completed',
            'language_code' => 'de',
            'text' => 'guten tag',
            'words' => [['text' => 'guten', 'start' => 0, 'end' => 100, 'confidence' => 0.9]],
        ])]);
        Bus::fake();

        $transcript = Transcript::factory()->processing('prov_lang')->create();

        $this->poll($transcript);

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertSame('de', $transcript->language_code);
        $this->assertStringContainsString('language', strtolower($transcript->error));
        $this->assertSame(0, $transcript->words()->count());
    }

    public function test_poll_times_out_after_the_configured_number_of_polls(): void
    {
        config(['services.assemblyai.max_polls' => 3]);
        Http::fake(['*/v2/transcript/prov_slow' => Http::response(['status' => 'processing'])]);
        Bus::fake();

        $transcript = Transcript::factory()->processing('prov_slow')->create(['poll_count' => 3]);

        $this->poll($transcript);

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertStringContainsString('timed out', strtolower($transcript->error));
        Bus::assertNotDispatched(PollTranscription::class);
    }

    public function test_poll_counts_each_attempt(): void
    {
        Http::fake(['*/v2/transcript/prov_count' => Http::response(['status' => 'processing'])]);
        Bus::fake();

        $transcript = Transcript::factory()->processing('prov_count')->create(['poll_count' => 0]);

        $this->poll($transcript);

        $this->assertSame(1, $transcript->refresh()->poll_count);
        Bus::assertDispatched(PollTranscription::class);
    }

    public function test_a_permanently_failing_submit_job_marks_the_transcript_failed(): void
    {
        $transcript = Transcript::factory()->create(['status' => Transcript::STATUS_QUEUED]);

        (new SubmitTranscription($transcript))->failed(new RuntimeException('upload exploded'));

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertStringContainsString('upload exploded', $transcript->error);
    }

    public function test_a_permanently_failing_poll_job_marks_the_transcript_failed(): void
    {
        $transcript = Transcript::factory()->processing('prov_dead')->create();

        (new PollTranscription($transcript))->failed(new RuntimeException('provider unreachable'));

        $transcript->refresh();
        $this->assertSame(Transcript::STATUS_FAILED, $transcript->status);
        $this->assertStringContainsString('provider unreachable', $transcript->error);
    }
}
