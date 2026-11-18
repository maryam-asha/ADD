<?php

namespace Add\Hr\Integrations;

use Add\Hr\Contracts\AccountProvisioner;
use Add\Hr\Core\Models\Employee;

/**
 * The default: the package creates no accounts and has none to revoke.
 */
class NullAccountProvisioner implements AccountProvisioner
{
    public function authorizeLink(int|string $userId): void {}

    public function provision(Employee $employee, array $person): int|string|null
    {
        return null;
    }

    public function search(string $term, int $limit): array
    {
        return [];
    }

    public function deprovision(Employee $employee): void {}
}
