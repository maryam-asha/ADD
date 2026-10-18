<?php

namespace Add\Hr\Payroll\Models;

use Add\Hr\Payroll\Enums\PayComponentKind;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * A pay element (basic salary, transport allowance, social insurance, income
 * tax, overtime, ...) and the calculator that computes it.
 */
class PayComponent extends Model
{
    use HasTranslations;

    protected $table = 'hr_pay_components';

    protected $fillable = [
        'code',
        'name',
        'kind',
        'calculator',
        'config',
        'applies_to_all',
        'taxable',
        'insurable',
        'sort',
        'is_active',
    ];

    protected $casts = [
        'name' => 'array',
        'kind' => PayComponentKind::class,
        'config' => 'array',
        'applies_to_all' => 'boolean',
        'taxable' => 'boolean',
        'insurable' => 'boolean',
        'sort' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'applies_to_all' => false,
        'taxable' => false,
        'insurable' => false,
        'sort' => 100,
        'is_active' => true,
    ];

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->config ?? [])[$key] ?? $default;
    }
}
