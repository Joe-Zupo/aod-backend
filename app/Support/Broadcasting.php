<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

class Broadcasting
{
    /**
     * Dispatch a broadcastable event without letting the broadcaster (a real
     * synchronous HTTP call to Pusher, since every event in this app is
     * ShouldBroadcastNow) take the caller down with it. Deferred past the
     * end of any open transaction via DB::afterCommit() — fires immediately
     * if there's no open transaction — so the network call never happens
     * while a row lock from the triggering write is still held, and a
     * broadcaster failure can never roll back work that already succeeded.
     * Failures are reported (logged), not silently swallowed.
     */
    public static function safely(object $event): void
    {
        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }
}
