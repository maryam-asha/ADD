<?php

namespace Add\Hr\Core\Models;

use Add\Hr\Database\Factories\DocumentTypeFactory;
use Add\Hr\Support\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A kind of employee document (ID card, signed contract, permit, ...).
 * A required type must be on file for every employee whose current contract
 * type it applies to.
 */
class DocumentType extends Model
{
    use HasFactory;
    use HasTranslations;

    protected $table = 'hr_document_types';

    protected $guarded = [];

    protected $casts = [
        'name' => 'array',
        'is_required' => 'boolean',
        'contract_types' => 'array',
        'has_expiry' => 'boolean',
        'is_active' => 'boolean',
    ];

    protected $attributes = [
        'is_required' => false,
        'has_expiry' => false,
        'is_active' => true,
    ];

    public function documents(): HasMany
    {
        return $this->hasMany(EmployeeDocument::class);
    }

    public function appliesTo(?string $contractType): bool
    {
        return $this->contract_types === null || in_array($contractType, $this->contract_types, true);
    }

    protected static function newFactory(): DocumentTypeFactory
    {
        return DocumentTypeFactory::new();
    }
}
