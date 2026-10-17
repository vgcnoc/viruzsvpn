<?php

namespace App\Policies;

use App\Models\User;
use App\Models\VpnServer;

final class VpnServerPolicy
{
    public function before(User $user): ?bool
    {
        return $user->role === 'administrator' ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return false;
    }

    public function view(User $user, VpnServer $server): bool
    {
        return false;
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, VpnServer $server): bool
    {
        return false;
    }

    public function delete(User $user, VpnServer $server): bool
    {
        return false;
    }
}
