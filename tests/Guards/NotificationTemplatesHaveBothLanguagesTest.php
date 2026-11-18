<?php

namespace Tests\Guards;

use Illuminate\Support\Arr;
use PHPUnit\Framework\TestCase;

/**
 * lang/{ar,en}/notifications.php is the catalog of every notification: the
 * two languages hold the same keys, and every template has both a title and
 * a body — the inbox and each device render it in either language.
 */
class NotificationTemplatesHaveBothLanguagesTest extends TestCase
{
    public function test_ar_and_en_hold_the_same_keys(): void
    {
        $ar = array_keys($this->templates('ar'));
        $en = array_keys($this->templates('en'));

        $this->assertSame([], array_values(array_diff($en, $ar)), 'Missing in lang/ar/notifications.php');
        $this->assertSame([], array_values(array_diff($ar, $en)), 'Missing in lang/en/notifications.php');
    }

    public function test_every_template_has_a_title_and_a_body(): void
    {
        foreach (['ar', 'en'] as $locale) {
            $lines = $this->templates($locale);

            foreach (array_keys($lines) as $key) {
                if (! preg_match('/^(.+)\.(title|body)$/', $key, $match)) {
                    continue;
                }

                $sibling = $match[1].'.'.($match[2] === 'title' ? 'body' : 'title');

                $this->assertNotSame('', trim((string) $lines[$key]), "{$locale}: {$key} is empty");
                $this->assertArrayHasKey($sibling, $lines, "{$locale}: {$key} has no {$sibling}");
            }
        }
    }

    /** @return array<string, string> */
    private function templates(string $locale): array
    {
        return Arr::dot(require dirname(__DIR__, 2)."/lang/{$locale}/notifications.php");
    }
}
