<?php

namespace App\Domain\Forms\Models;

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
 * One question on a form, whichever model owns the form.
 *
 * Identity — name, phone, email — is never a field: it comes from the
 * member's account. See ReservedFieldKeys.
 */
class FormField extends Model implements FormFieldContract
{
    use HasFactory, HasTranslations;

    protected $fillable = [
        'form_id',
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
            'form_id' => 'integer',
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
        // backstop for tinker, factories and seeders run on their own, which
        // have no request layer in front of them (DatabaseSeeder mutes model
        // events, this included). Only a key being written is checked, so a
        // row that predates the rule can still be relabelled or hidden.
        static::saving(function (self $field) {
            if ($field->isDirty('key') && ReservedFieldKeys::isReserved((string) $field->key)) {
                throw new RuntimeException(
                    "Field key \"{$field->key}\" is reserved: identity comes from the member's account."
                );
            }
        });

        static::saved(fn (self $field) => $field->form?->notifyOwnerOfFieldChange());
        static::deleted(fn (self $field) => $field->form?->notifyOwnerOfFieldChange());
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(Form::class);
    }

    public function answers(): HasMany
    {
        return $this->hasMany(FormAnswer::class);
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

    public function fieldLabel(?string $locale = null): string
    {
        return $this->translate('label', $locale) ?: $this->key;
    }
}
