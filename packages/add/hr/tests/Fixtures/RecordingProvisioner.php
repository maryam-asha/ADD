<?php

namespace Add\Hr\Tests\Fixtures;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;
use Add\Hr\Support\HrException;

/**
 * Records what the package asked of the host.
 */
class RecordingProvisioner implements AccountProvisioner
{
    /** @var list<int> */
    public array $deprovisioned = [];

    /** @var list<int|string> */
    public array $authorized = [];

    public function __construct(
        private readonly int|string|null $provisionAs = null,
        private readonly bool $refuseLinks = false,
    ) {}

    public function authorizeLink(int|string $userId): void
    {
        if ($this->refuseLinks) {
            HrException::fail('hr::system.forbidden', [], 403);
        }

        $this->authorized[] = $userId;
    }

    public function provision(Employee $employee, array $person): int|string|null
    {
        return $this->provisionAs;
    }

    public function search(string $term, int $limit): array
    {
        return [];
    }

    public function deprovision(Employee $employee): void
    {
        $this->deprovisioned[] = $employee->id;
    }
}
