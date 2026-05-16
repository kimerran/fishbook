<?php

namespace App\Policies;

use App\Models\Background;
use App\Models\User;

class BackgroundPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, Background $background): bool
    {
        return $user->id === $background->user_id || $user->is_admin;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function update(User $user, Background $background): bool
    {
        return $user->id === $background->user_id;
    }

    public function delete(User $user, Background $background): bool
    {
        return $user->id === $background->user_id;
    }
}
