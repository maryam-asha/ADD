<?php

namespace Add\Hr\Tests\Fixtures;

use Add\Hr\Contracts\HrNotifier;
use Add\Hr\Core\Models\Employee;

/**
 * Records every notification the package asked the host to send.
 */
class RecordingNotifier implements HrNotifier
{
    /** @var list<array{0: string, 1: string, 2: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<array{0: int, 1: string, 2: array<string, mixed>}> */
    public array $employeeCalls = [];

    public function notifyHolders(string $ability, string $key, array $placeholders = []): void
    {
        $this->calls[] = [$ability, $key, $placeholders];
    }

    public function notifyEmployee(Employee $employee, string $key, array $placeholders = []): void
    {
        $this->employeeCalls[] = [$employee->id, $key, $placeholders];
    }

    /**
     * @return list<string>
     */
    public function keysFor(Employee $employee): array
    {
        return array_values(array_map(
            fn (array $call) => $call[1],
            array_filter($this->employeeCalls, fn (array $call) => $call[0] === $employee->id),
        ));
    }
}
