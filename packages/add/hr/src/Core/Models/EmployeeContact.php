<?php

namespace Add\Hr\Core\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * An emergency contact.
 */
class EmployeeContact extends Model
{
    protected $table = 'hr_employee_contacts';

    protected $fillable = [
        'employee_id',
        'name',
        'relation',
        'phone',
    ];
}
