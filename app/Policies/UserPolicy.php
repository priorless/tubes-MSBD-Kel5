<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

/**
 * Aturan Manajemen User: hanya CEO & HRD, hanya akun staff internal, dan akun CEO hanya dikelola oleh CEO.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasRole(Role::Ceo, Role::Hrd);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, User $target): bool
    {
        if (! $this->viewAny($actor) || $target->hasRole(Role::KaryawanLapangan)) {
            return false;
        }

        return $actor->hasRole(Role::Ceo) || ! $target->hasRole(Role::Ceo);
    }

    public function delete(User $actor, User $target): bool
    {
        return $actor->isNot($target) && $this->update($actor, $target);
    }
}
