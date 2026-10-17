<?php

namespace Add\Hr\Approvals\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The ordered steps a request type goes through. Requests copy the steps
 * when submitted, so editing a flow never changes requests already open.
 */
class ApprovalFlow extends Model
{
    protected $table = 'hr_approval_flows';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'steps' => 'array',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_active' => true,
    ];
}
