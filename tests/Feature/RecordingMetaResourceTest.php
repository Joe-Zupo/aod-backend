<?php

namespace Tests\Feature;

use App\Http\Resources\RecordingMetaResource;
use App\Models\AodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingMetaResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exposes_client_started_at(): void
    {
        $record = AodRecord::factory()->create(['client_started_at' => '2026-09-12 10:00:00.250']);

        $array = (new RecordingMetaResource($record))->toArray(request());

        $this->assertArrayHasKey('client_started_at', $array);
        $this->assertNotNull($array['client_started_at']);
    }

    public function test_it_returns_null_when_not_captured(): void
    {
        $record = AodRecord::factory()->create(['client_started_at' => null]);

        $array = (new RecordingMetaResource($record))->toArray(request());

        $this->assertNull($array['client_started_at']);
    }
}
