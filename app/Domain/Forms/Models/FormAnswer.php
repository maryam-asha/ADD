<?php

namespace App\Domain\Forms\Models;

use App\Concerns\HasTranslations;
use App\Support\Forms\FieldType;
use App\Support\Forms\FieldValueRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one field. It carries a snapshot of the field it answered
 * (key, type, label), so it keeps its meaning after that field is edited
 * or deleted — `form_field_id` goes null on delete rather than taking the
 * answer with it.
 */
class FormAnswer extends Model
{
    use HasTranslations;

    protected $fillable = [
        'form_submission_id',
        'form_field_id',
        'field_key',
        'field_type',
        'field_label',
        'value_text',
        'value_json',
        'value_file',
    ];

    protected function casts(): array
    {
        return [
            'form_submission_id' => 'integer',
            'form_field_id' => 'integer',
            'field_type' => FieldType::class,
            'field_label' => 'array',
            'value_json' => 'array',
        ];
    }

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FormSubmission::class, 'form_submission_id');
    }

    public function field(): BelongsTo
    {
        return $this->belongsTo(FormField::class, 'form_field_id');
    }

    /** The stored value, whichever of the three columns holds it. */
    public function resolvedValue(): mixed
    {
        return app(FieldValueRouter::class)
            ->resolve($this->value_text, $this->value_json, $this->value_file);
    }

    /** The field's current key, or the one it had when answered. */
    public function displayKey(): string
    {
        return $this->field?->key ?? $this->field_key;
    }

    /** The field's current label, or the one it had when answered. */
    public function displayLabel(?string $locale = null): string
    {
        return $this->field?->fieldLabel($locale)
            ?? ($this->translate('field_label', $locale) ?: $this->field_key);
    }

    /** An answer row with the snapshot taken and the right value column filled. */
    public static function fromField(FormSubmission $submission, FormField $field, mixed $value): self
    {
        $answer = new self([
            'form_submission_id' => $submission->id,
            'form_field_id' => $field->id,
            'field_key' => $field->key,
            'field_type' => $field->type,
            'field_label' => $field->label,
        ]);

        $answer->forceFill(app(FieldValueRouter::class)->route($field->fieldType(), $value));

        return $answer;
    }
}
