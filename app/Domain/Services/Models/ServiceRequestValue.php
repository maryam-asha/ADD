<?php

namespace App\Domain\Services\Models;

use App\Domain\Services\Enums\FieldType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceRequestValue extends Model
{
    protected $fillable = [
        'service_request_id',
        'service_type_field_id',
        'value_text',
        'value_json',
        'value_file',
    ];

    protected function casts(): array
    {
        return [
            'value_json' => 'array',
        ];
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function serviceRequest(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class);
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(ServiceTypeField::class, 'service_type_field_id');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Return the stored value in a unified format regardless of storage column,
     * useful for API serialization.
     */
    public function resolvedValue(): mixed
    {
        return match (true) {
            $this->value_file !== null => $this->value_file,
            $this->value_json !== null => $this->value_json,
            default                    => $this->value_text,
        };
    }

    /**
     * Set the correct storage column based on the field type.
     */
    public static function fromFieldValue(
        int $requestId,
        ServiceTypeField $field,
        mixed $value,
    ): self {
        $instance = new self([
            'service_request_id'    => $requestId,
            'service_type_field_id' => $field->id,
        ]);

        if ($field->type->storesFile()) {
            $instance->value_file = $value; // already stored path
        } elseif ($field->type->storesJson()) {
            $instance->value_json = $value;
        } else {
            $instance->value_text = (string) $value;
        }

        return $instance;
    }
}
