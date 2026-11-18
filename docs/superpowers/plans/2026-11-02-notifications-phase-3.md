# Notifications Phase 3 (Reminders) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task.

**Goal:** Notices sent because a time is near, not because something happened, each sent exactly once.

**Spec:** `docs/superpowers/specs/2026-11-02-notifications-design.md`. The approved reminder timings and wording are there.

## Design

- **Send once.** A `notification_claims` table (`user_id`, `dedupe_key` `string(191)`, unique together).
  - Ruling: the claims got a table of their own rather than a column on `notification_logs`. That table's `channel` is a legacy enum without `database`; SQLite silently drops the row, and MySQL's `INSERT IGNORE` would store an empty value.
  - `NotificationService::sendToUserOnce(User, string $dedupeKey, …): bool` claims the key with an insert-or-ignore, and sends only when the claim is new. Two runs at once cannot both send.
  - Staged reminders carry the stage in the key: `membership.expiring_soon:12:7d` and `…:1d`.
- **One runner.**
  - `App\Services\Notification\ReminderRunner::run(): array<string,int>` holds one method per reminder.
  - The command `notifications:send-reminders` runs it every five minutes, `withoutOverlapping`, on the host's existing cron.
- **Never late, never for something gone.** Each reminder looks only inside its window and only at live subjects: confirmed bookings, approved registrations, active and not-renewed plans, active leases, open invoices, unpaid sessions. A window the runner missed is skipped, not sent late.

## Reminders

| Key | Window | Recipients |
|---|---|---|
| `booking.reminder` | confirmed, starts in (now, now+30m] | member |
| `booking.ending_soon` | checked in, not out, ends in (now, now+10m] | member |
| `event.reminder` (`_without_location`) | stage `1d`: starts in (now+2h, now+24h]; stage `2h`: (now, now+2h] | approved attendees with an account |
| `membership.expiring_soon` | stage `7d`: ends in (now+1d, now+7d]; stage `1d`: (now, now+1d]. Without auto-renew, not yet renewed | holder (member or company admins) |
| `membership.expired` | ended in (now−24h, now], not renewed | holder |
| `lease.ending_soon` | active, no auto-renew, ends in [today+25d, today+30d] | company admins |
| `invoice.overdue` | open, due in [today−7d, today−1d] | payer (company admins / provider accounts) |
| `coworking.unpaid_reminder` | session or booking checked out in (now−48h, now−24h], still unpaid, owes points | member |

## Tasks

1. `dedupe_key` migration + `sendToUserOnce` / `sendToUsersOnce` (tests: one send per key across two runs; a different stage sends again).
2. Booking, event and session reminders (tests per window, plus a cancelled booking and an already-started one getting nothing).
3. Plan, lease and invoice reminders (tests: an auto-renewing plan and a renewed plan get nothing; an invoice overdue for months gets nothing).
4. The command and its schedule, Pint, the full suite once, a self-review, then a commit of only these files.
