<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Support\Casts\DateOnly;
use Illuminate\Database\Eloquent\Model;

/**
 * A family member the employee declares (payroll allowances and insurance
 * read these in later phases).
 */
class EmployeeDependent extends Model
{
    protected $table = 'hr_employee_dependents';

    protected $fillable = [
        'employee_id',
        'name',
        'relation',
        'date_of_birth',
    ];

    protected $casts = [
        'date_of_birth' => DateOnly::class,
    ];
}
