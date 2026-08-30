<?php

namespace App\Support;

/**
 * These three role names must never be deletable or renamable through the
 * role-management endpoints — other code depends on these exact literal
 * strings existing (member-vs-dashboard login separation, first-admin
 * bootstrap).
 */
final class ProtectedRoles
{
    public const NAMES = ['member', 'operations', 'admin'];
}
