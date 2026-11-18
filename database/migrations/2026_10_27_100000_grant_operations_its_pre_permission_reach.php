<?php

use App\Services\Permissions\PermissionSyncService;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Dashboard access by permission alone
 * (docs/superpowers/specs/2026-10-27-permission-only-dashboard-access-design.md,
 * docs/decisions/rbac-permission-pilot.md, 2026-10-27).
 *
 * The admin API stopped checking `role:admin|operations`: every route now
 * names its own permission. This creates those permissions (and gives admin
 * all of them, as every sync does), then grants `operations` the permission
 * of every route it reached before — every route of routes/api/v1/admin.php
 * as of commit e256252e outside the `role:admin` group and outside the blocks
 * that were already permission-gated — so nobody loses access on deploy.
 * What operations already holds is kept, and the Branches grant
 * PermissionSeeder used to give it is part of the list, so a fresh database
 * ends where a live one does.
 *
 * The list is a snapshot, written out, not computed from today's routes: a
 * later route change must not silently change what this step granted. A
 * route guarded by any of several permissions (`a|b`) contributes all of
 * them. Names a later change has removed are skipped, so a fresh database
 * never gains a permission that guards nothing.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private const OPERATIONS_REACH = [
        'amenities.create', 'amenities.delete', 'amenities.update', 'amenities.view',
        'announcements.create', 'announcements.delete', 'announcements.update', 'announcements.view',
        'app-content.update', 'app-content.view',
        'bookings.view',
        'buildings.create', 'buildings.update', 'buildings.view',
        'business-hour-exceptions.create', 'business-hour-exceptions.update', 'business-hour-exceptions.view',
        'business-hours.create', 'business-hours.update', 'business-hours.view',
        'cash-register.manage', 'cash-register.view',
        'community-members.create', 'community-members.delete', 'community-members.update', 'community-members.view',
        'companies.manage_members', 'companies.update_status', 'companies.view',
        'contact-links.create', 'contact-links.delete', 'contact-links.update', 'contact-links.view',
        'coupons.view',
        'currencies.create', 'currencies.update', 'currencies.view',
        'device-capabilities.create', 'device-capabilities.update', 'device-capabilities.view',
        'devices.create', 'devices.update', 'devices.view',
        'error-logs.view',
        'event-categories.create', 'event-categories.delete', 'event-categories.update', 'event-categories.view',
        'events.create', 'events.delete', 'events.manage_attendees', 'events.update', 'events.view',
        'exchange-rates.create', 'exchange-rates.view',
        'floors.create', 'floors.update', 'floors.view',
        'founders.create', 'founders.delete', 'founders.update', 'founders.view',
        'item-sales.create', 'item-sales.view',
        'members.view',
        'partners.create', 'partners.delete', 'partners.update', 'partners.view',
        'payment-methods.view',
        'payments.view',
        'plan-modules.create', 'plan-modules.delete', 'plan-modules.update', 'plan-modules.view',
        'plan-purchases.create',
        'plans.create', 'plans.delete', 'plans.update', 'plans.view',
        'private-office-requests.create', 'private-office-requests.delete', 'private-office-requests.update', 'private-office-requests.view',
        'reception.approve', 'reception.book', 'reception.check_in', 'reception.record_consumption', 'reception.settle_payment', 'reception.view',
        'reports.view',
        'resource-types.create', 'resource-types.delete', 'resource-types.update', 'resource-types.view',
        'sale-items.create', 'sale-items.delete', 'sale-items.update', 'sale-items.view',
        'seats-desks.create', 'seats-desks.update', 'seats-desks.view',
        'settings.view',
        'site-inquiries.update', 'site-inquiries.view',
        'spaces.create', 'spaces.update', 'spaces.view',
        'users.view',
        'wallet-top-ups.create', 'wallet-top-ups.view',
        'zones.create', 'zones.update', 'zones.view',
    ];

    /**
     * What PermissionSeeder granted operations since the Branches pilot. A
     * live database already holds it (kept); a fresh one gets it here now
     * that the seeder only syncs.
     *
     * @var list<string>
     */
    private const SEEDED_BEFORE = ['branches.create', 'branches.update', 'branches.view'];

    public function up(): void
    {
        app(PermissionSyncService::class)->sync();

        $existing = Permission::query()
            ->where('guard_name', 'web')
            ->whereIn('name', [...self::OPERATIONS_REACH, ...self::SEEDED_BEFORE])
            ->pluck('name')
            ->all();

        // None of them exists only if the routes were never loaded: granting
        // nothing would lock operations out on deploy without a word.
        if ($existing === []) {
            throw new RuntimeException('Permission sync derived none of the operations permissions; are the admin routes loaded?');
        }

        Role::findOrCreate('operations', 'web')->givePermissionTo($existing);
    }

    /**
     * Not reversed: which of these operations held before cannot be told
     * apart from what this step gave it, and taking them away would lock
     * operations out of the API it had a moment ago.
     */
    public function down(): void {}
};
