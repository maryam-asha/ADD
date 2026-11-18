<?php

namespace Tests\Guards;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * A `notification_logs` row records a push that was really sent. Writing one
 * anywhere else is how notices used to be faked — a row marked `sent` that
 * nobody received. Every notice goes through NotificationService.
 */
class NotificationLogsAreWrittenOnlyBySendingTest extends TestCase
{
    private const SENDER = 'app/Services/Notification/NotificationService.php';

    public function test_only_the_notification_service_writes_notification_logs(): void
    {
        $root = dirname(__DIR__, 2);
        $offenders = [];

        foreach (Finder::create()->files()->in([$root.'/app', $root.'/packages'])->name('*.php') as $file) {
            $path = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

            if ($path !== self::SENDER
                && preg_match('/NotificationLog::(query\(\)->)?(create|insert|forceCreate|firstOrCreate|updateOrCreate)\b|notificationLogs\(\)->create/', $file->getContents())) {
                $offenders[] = $path;
            }
        }

        $this->assertSame([], $offenders, 'Send through NotificationService instead of writing notification_logs: '.implode(', ', $offenders));
    }
}
