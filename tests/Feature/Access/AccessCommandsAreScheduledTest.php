<?php

namespace Tests\Feature\Access;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccessCommandsAreScheduledTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_three_commands_run_clean_with_no_data(): void
    {
        $this->artisan('access:issue-grants-on-cancellation-window-close')->assertSuccessful();
        $this->artisan('access:revoke-grants-on-maintenance')->assertSuccessful();
        $this->artisan('access:expire-unactivated-grants')->assertSuccessful();
    }
}
