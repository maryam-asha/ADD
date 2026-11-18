<?php

namespace Tests\Guards;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * Staff are reached by the permissions they hold, never by a role name
 * (docs/decisions/rbac-permission-pilot.md, 2026-10-27): a custom role that
 * is given a permission gets its notices too. Every sender goes through
 * NotificationService::sendToHolders().
 */
class NotificationRecipientsComeFromPermissionsTest extends TestCase
{
    public function test_no_notification_sender_picks_recipients_by_role(): void
    {
        $offenders = [];

        foreach (Finder::create()->files()->in(dirname(__DIR__, 2).'/app')->name('*.php') as $file) {
            $source = $file->getContents();

            if (str_contains($source, 'NotificationService') && preg_match('/::role\(/', $source)) {
                $offenders[] = $file->getRelativePathname();
            }
        }

        $this->assertSame([], $offenders, 'Notify staff with NotificationService::sendToHolders(), not User::role(): '.implode(', ', $offenders));
    }
}
