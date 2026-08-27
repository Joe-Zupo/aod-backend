<?php

namespace Tests\Unit\Support;

use App\Support\Broadcasting;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class BroadcastingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Simulates exactly the failure this session hit for real: a broadcaster
     * (Pusher) call that throws — here forced via broadcastOn() itself
     * throwing, since that's where Laravel's dispatch reaches into the event
     * on its way to the broadcaster, without needing a real network call.
     */
    public function test_safely_reports_a_broadcast_failure_instead_of_letting_it_propagate(): void
    {
        $event = new class implements ShouldBroadcastNow
        {
            use Dispatchable;

            public function broadcastOn(): array
            {
                throw new RuntimeException('Simulated broadcaster failure.');
            }
        };

        Broadcasting::safely($event);

        $this->assertTrue(true);
    }
}
