<?php
declare(strict_types=1);
namespace TSF\Security;

/** Pure policy rule to integrate into Eloquent-scoped queries and write policies. */
final class LeadAccess
{
    public static function allowed(bool $active, string $role, int $userId, ?int $assignedUserId): bool
    {
        if (!$active) return false;
        return $role === 'admin' || ($role === 'advisor' && $assignedUserId !== null && $userId === $assignedUserId);
    }
}
