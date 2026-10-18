<?php

namespace Add\Hr\Core\Http\Controllers\Admin;

use Add\Hr\Audit\HrAudit;
use Add\Hr\Core\Http\Resources\EmployeeSensitiveResource;
use Add\Hr\Core\Models\Employee;
use Illuminate\Routing\Controller;

class EmployeeSensitiveController extends Controller
{
    /**
     * The decrypted national ID and bank account. Every read leaves an
     * audit entry naming who read whose.
     */
    public function show(Employee $employee): EmployeeSensitiveResource
    {
        HrAudit::record('sensitive_viewed', $employee);

        return new EmployeeSensitiveResource($employee);
    }
}
