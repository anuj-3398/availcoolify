<?php

namespace App\Enums;

enum Role: string
{
    // Avail: read-only access to the projects ticked for them, optionally time-limited.
    case GUEST = 'guest';
    case MEMBER = 'member';
    case ADMIN = 'admin';
    case OWNER = 'owner';

    public function rank(): int
    {
        return match ($this) {
            self::GUEST => 0,
            self::MEMBER => 1,
            self::ADMIN => 2,
            self::OWNER => 3,
        };
    }

    public function lt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() < $role->rank();
    }

    public function gt(Role|string $role): bool
    {
        if (is_string($role)) {
            $role = Role::from($role);
        }

        return $this->rank() > $role->rank();
    }
}
