<?php

namespace Add\Hr\Tests\Fixtures;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;

/**
 * Records what the package asked of the host.
 */
class RecordingProvisioner implements AccountProvisioner
{
    /** @var list<int> */
    public array $deprovisioned = [];

    public function __construct(private readonly int|string|null $provisionAs = null) {}

    public function provision(Employee $employee): int|string|null
    {
        return $this->provisionAs;
    }

    public function deprovision(Employee $employee): void
    {
        $this->deprovisioned[] = $employee->id;
    }
}
