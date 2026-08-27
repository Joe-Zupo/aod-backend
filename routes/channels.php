<?php

use App\Models\Session;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Reuses SessionPolicy::view rather than re-deriving "is this user allowed
// to see this session," so the live channel is never more permissive (or
// more restrictive) than the REST endpoint it's paired with.
Broadcast::channel('session.{session}', function (User $user, Session $session) {
    return $user->can('view', $session);
});
