<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * PRD decision #20: the system runs behind a VPN — any external link is a
 * functional defect, not an enhancement. Mirrors the frontend's
 * no-external-urls.spec.ts guard (ADDOS/.claude/rules/env-and-secrets.md).
 *
 * Scans app/, database/ and routes/ only — config/ is excluded because it is
 * mostly framework scaffolding whose comments legitimately point at
 * vendor documentation (MDN, AWS, GitHub) that is never requested at
 * runtime. A future integration that genuinely needs an external host
 * (e.g. TTLock's Cloud API in Phase 6) is added to $allowlist with a
 * one-line justification — the list starts empty on purpose.
 */
class NetworkIsolationTest extends TestCase
{
    use ScansSourceFiles;

    /** @var list<string> hosts explicitly cleared for outbound use, with why */
    private const ALLOWLIST = [
        // 'api.ttlock.com' => 'TTLock Cloud API, Phase 6 access-control integration',
    ];

    public function test_no_external_host_is_referenced_in_app_database_or_routes(): void
    {
        $violations = [];
        $allowedHosts = array_keys(self::ALLOWLIST);

        foreach (['app', 'database', 'routes'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $contents) {
                if (! preg_match_all('#https?://([a-zA-Z0-9.-]+)#i', $contents, $matches)) {
                    continue;
                }

                foreach ($matches[1] as $host) {
                    $host = strtolower($host);
                    $isLocal = $host === 'localhost' || $host === '127.0.0.1' || str_ends_with($host, '.local');

                    if (! $isLocal && ! in_array($host, $allowedHosts, true)) {
                        $violations[] = "{$path} references external host \"{$host}\"";
                    }
                }
            }
        }

        $this->assertSame([], $violations, "Decision #20 forbids external hosts (network is VPN-isolated):\n".implode("\n", $violations));
    }
}
