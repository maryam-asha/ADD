<?php

namespace App\Domain\Services\Models;

use App\Support\Forms\FieldValueRouter;
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
        return app(FieldValueRouter::class)
            ->resolve($this->value_text, $this->value_json, $this->value_file);
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
            'service_request_id' => $requestId,
            'service_type_field_id' => $field->id,
        ]);

        $instance->forceFill(
            app(FieldValueRouter::class)->route($field->fieldType(), $value)
        );

        return $instance;
    }
}
