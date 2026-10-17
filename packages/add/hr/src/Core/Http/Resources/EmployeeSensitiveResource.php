<?php

namespace Add\Hr\Core\Http\Resources;

use Add\Hr\Core\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The decrypted sensitive fields. Returned only behind
 * hr.employees.view_sensitive, and every read is audited.
 *
 * @mixin Employee
 */
class EmployeeSensitiveResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'national_id' => $this->national_id,
            'bank_account' => $this->bank_account,
        ];
    }
}
