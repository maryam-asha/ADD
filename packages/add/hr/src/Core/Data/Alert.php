<?php

namespace Add\Hr\Core\Data;

use Add\Hr\Core\Enums\AlertType;
use Carbon\CarbonImmutable;

/**
 * Something about an employee that needs a decision by a date.
 */
final readonly class Alert
{
    /**
     * @param  array<string, string>  $employeeName
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public AlertType $type,
        public int $employeeId,
        public ?string $employeeNumber,
        public array $employeeName,
        public CarbonImmutable $dueOn,
        public int $daysLeft,
        public array $meta = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->value,
            'employee' => [
                'id' => $this->employeeId,
                'employee_number' => $this->employeeNumber,
                'name' => $this->employeeName,
            ],
            'due_on' => $this->dueOn->toDateString(),
            'days_left' => $this->daysLeft,
            'meta' => $this->meta,
        ];
    }
}
