<?php

namespace Tests\Guards;

use Tests\Guards\Concerns\ScansSourceFiles;
use Tests\TestCase;

/**
 * A person's national ID and bank account live apart, in
 * user_sensitive_profiles, so nothing that loads a user or a profile can
 * carry them by accident. That holds only while one service is the way in:
 * any other file naming the model or the table is a second door (PRD §9:
 * written prose is a request, not a guarantee).
 */
class SensitiveProfileStaysContainedTest extends TestCase
{
    use ScansSourceFiles;

    private const ALLOWED = [
        'app/Domain/Identity/Models/UserSensitiveProfile.php',
        'app/Domain/Identity/Services/SensitiveProfileService.php',
    ];

    public function test_only_the_sensitive_profile_service_touches_the_table(): void
    {
        $violations = [];

        foreach (['app', 'routes'] as $dir) {
            foreach ($this->phpFilesIn(base_path($dir)) as $path => $source) {
                if (in_array($path, self::ALLOWED, true)) {
                    continue;
                }

                if (str_contains($source, 'UserSensitiveProfile') || str_contains($source, 'user_sensitive_profiles')) {
                    $violations[] = $path;
                }
            }
        }

        $this->assertSame([], $violations, 'Read and write sensitive data only through App\\Domain\\Identity\\Services\\SensitiveProfileService.');
    }
}
