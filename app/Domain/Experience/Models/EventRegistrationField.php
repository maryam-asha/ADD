<?php

namespace App\Domain\Experience\Models;

use App\Concerns\HasTranslations;
use App\Support\Forms\FieldType;
use App\Support\Forms\FormFieldContract;
use App\Support\Forms\ReservedFieldKeys;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * One question on an event's registration form.
 *
 * Identity — name, phone, email — is never a field here: it comes from the
 * member's token and is returned to the client as a separate read-only
 * block. See ReservedFieldKeys.
 */
class EventRegistrationField extends Model implements FormFieldContract
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'event_id',
        'key',
        'label',
        'placeholder',
        'type',
        'options',
        'validation',
        'is_required',
        'is_active',
        'order',
    ];

    protected function casts(): array
    {
        return [
            'label' => 'array',
            'placeholder' => 'array',
            'type' => FieldType::class,
            'options' => 'array',
            'validation' => 'array',
            'is_required' => 'boolean',
            'is_active' => 'boolean',
            'order' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // The Form Requests reject reserved keys with a 422; this is the
        // backstop for a seeder or a tinker session, which have no request
        // layer in front of them.
        static::saving(function (self $field) {
            if (ReservedFieldKeys::isReserved((string) $field->key)) {
                throw new RuntimeException(
                    "Field key \"{$field->key}\" is reserved: identity comes from the member's account."
                );
            }
        });
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    public function values(): HasMany
    {
        return $this->hasMany(EventRegistrationFieldValue::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    // ── Shared form contract ─────────────────────────────────────────────────

    public function fieldKey(): string
    {
        return (string) $this->key;
    }

    public function fieldType(): FieldType
    {
        return $this->type;
    }

    public function fieldOptions(): ?array
    {
        return $this->options;
    }

    public function fieldValidation(): array
    {
        return $this->validation ?? [];
    }

    public function isFieldRequired(): bool
    {
        return (bool) $this->is_required;
    }
}
