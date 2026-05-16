<?php

namespace App\Policies;

use App\Models\User;

class FishPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, mixed $fish): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, mixed $fish): bool
    {
        return false;
    }

    public function delete(User $user, mixed $fish): bool
    {
        return false;
    }
}
