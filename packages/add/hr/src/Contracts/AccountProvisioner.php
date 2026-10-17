<?php

namespace Add\Hr\Contracts;

use Add\Hr\Core\Models\Employee;

/**
 * How the host gives an employee an account, and takes back what the
 * employment gave them. Bound by the host; the package default does
 * nothing (employees without accounts).
 */
interface AccountProvisioner
{
    /**
     * Called inside the hire (or rehire) transaction when the request named
     * no user. Return the id of the user to link — an existing account or a
     * new one — or null for none. Throwing aborts the hire.
     */
    public function provision(Employee $employee): int|string|null;

    /**
     * Called once, after the employment's last working day has passed
     * (immediately for a termination dated in the past). Remove what being
     * an employee granted; the account itself may serve other purposes.
     */
    public function deprovision(Employee $employee): void;
}
