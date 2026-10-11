<?php

namespace App\Support;

/**
 * These role names must never be deletable or renamable through the
 * role-management endpoints — other code depends on these exact literal
 * strings existing (member-vs-dashboard login separation, first-admin
 * bootstrap, the provider route group).
 */
final class ProtectedRoles
{
    public const NAMES = ['member', 'operations', 'admin', 'provider'];

    /** Roles outside the admin permission system: they never hold an admin-panel permission. */
    public const OUTSIDE_ADMIN_PERMISSIONS = ['member', 'provider'];
}
