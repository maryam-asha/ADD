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
     * Called before linking a user the request named explicitly. Linking an
     * account gives HR power over it — deprovision() runs when the
     * employment ends — so refuse (throw) when the acting user may not
     * hold that power over this account. provision() must apply the same
     * check to any existing account it returns.
     */
    public function authorizeLink(int|string $userId): void;

    /**
     * Called inside the hire (or rehire) transaction when the request named
     * no user. $person holds the request's person fields (PersonFields) —
     * the employee row may not keep them. Return the id of the user to link
     * — an existing account or a new one — or null for none. Throwing aborts
     * the hire.
     *
     * @param  array<string, mixed>  $person
     */
    public function provision(Employee $employee, array $person): int|string|null;

    /**
     * Accounts matching $term (name, phone or email) that HR may hire, for
     * the hire form to pick from: at most $limit rows of
     * {id, name, phone, email}. A host with no accounts returns [].
     *
     * @return list<array{id: int|string, name: ?string, phone: ?string, email: ?string}>
     */
    public function search(string $term, int $limit): array;

    /**
     * Called once, after the employment's last working day has passed
     * (immediately for a termination dated in the past). Remove what being
     * an employee granted; the account itself may serve other purposes.
     */
    public function deprovision(Employee $employee): void;
}
