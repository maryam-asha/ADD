<?php

use App\Domain\Identity\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
 * The private channels the socket server lets a client subscribe to, each
 * signed by POST /api/broadcasting/auth (bootstrap/app.php). What goes over
 * them is only a nudge — an event name and ids — and the client re-fetches
 * the endpoint it already reads (App\Domain\Booking\Events), so these checks
 * guard who learns that something happened, not the data itself.
 */

// One member's own updates: their coworking start confirmed, refused or
// expired, their session ended.
Broadcast::channel('member.{id}', fn (User $user, string $id) => (int) $user->id === (int) $id);

// The reception desk — one today, a single branch. Whoever may see the desk
// (`reception.view`, the permission its screens read with — any role, by
// permission, never by role name: docs/decisions/rbac-permission-pilot.md,
// 2026-10-27), signed in to the dashboard: the ability check keeps a
// member-app token of someone who also works the desk out, the same seal the
// admin route group holds (TokenPairService::MEMBER_APP_ABILITY). The
// dashboard's own session carries a TransientToken, which passes it.
Broadcast::channel('reception', fn (User $user) => $user->can('reception.view') && $user->tokenCan('dashboard'));
