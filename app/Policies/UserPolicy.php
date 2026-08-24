<?php

namespace App\Policies;

use App\Models\User;

class UserPolicy
{
    public function view(User $user, User $profile): bool
    {
        return $user->is($profile);
    }

    public function update(User $user, User $profile): bool
    {
        return $user->is($profile);
    }
}
