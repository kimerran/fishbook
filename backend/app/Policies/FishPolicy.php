<?php

namespace App\Policies;

use App\Models\Fish;
use App\Models\User;

class FishPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id || $user->is_admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id;
    }

    public function delete(User $user, Fish $fish): bool
    {
        return $user->id === $fish->user_id;
    }
}
