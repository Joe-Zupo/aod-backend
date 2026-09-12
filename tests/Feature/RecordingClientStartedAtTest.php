<?php

namespace Tests\Feature;

use App\Models\AodRecord;
use App\Models\VodRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecordingClientStartedAtTest extends TestCase
{
    use RefreshDatabase;

    public function test_aod_record_accepts_a_nullable_client_started_at(): void
    {
        $record = AodRecord::factory()->create(['client_started_at' => '2026-09-12 10:00:00.250']);

        $this->assertDatabaseHas('aod_records', ['id' => $record->id]);
        $this->assertNotNull($record->fresh()->client_started_at);
    }

    public function test_vod_record_accepts_a_nullable_client_started_at(): void
    {
        $record = VodRecord::factory()->create(['client_started_at' => '2026-09-12 10:00:00.400']);

        $this->assertDatabaseHas('vod_records', ['id' => $record->id]);
        $this->assertNotNull($record->fresh()->client_started_at);
    }

    public function test_client_started_at_defaults_to_null(): void
    {
        $aod = AodRecord::factory()->create();
        $vod = VodRecord::factory()->create();

        $this->assertNull($aod->fresh()->client_started_at);
        $this->assertNull($vod->fresh()->client_started_at);
    }
}
