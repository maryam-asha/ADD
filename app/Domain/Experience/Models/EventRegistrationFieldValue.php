<?php

namespace App\Domain\Experience\Models;

use App\Support\Forms\FieldValueRouter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer to one registration-form field.
 *
 * Lives in Experience, beside the field it answers, rather than in
 * Ecosystem beside EventAttendee: DomainLayerBoundaryTest allows
 * EventAttendee to reach into Experience, but not the reverse for any
 * other file. The attendee side is therefore expressed only as
 * EventAttendee::values() — there is no inverse belongsTo here, because
 * nothing needs one.
 */
class EventRegistrationFieldValue extends Model
{
    protected $fillable = [
        'event_attendee_id',
        'event_registration_field_id',
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

    public function field(): BelongsTo
    {
        return $this->belongsTo(EventRegistrationField::class, 'event_registration_field_id');
    }

    /** The stored value, whichever of the three columns holds it. */
    public function resolvedValue(): mixed
    {
        return app(FieldValueRouter::class)
            ->resolve($this->value_text, $this->value_json, $this->value_file);
    }

    /** Build a value row with the right column filled for the field's type. */
    public static function fromFieldValue(
        int $attendeeId,
        EventRegistrationField $field,
        mixed $value,
    ): self {
        $instance = new self([
            'event_attendee_id' => $attendeeId,
            'event_registration_field_id' => $field->id,
        ]);

        $instance->forceFill(
            app(FieldValueRouter::class)->route($field->fieldType(), $value)
        );

        return $instance;
    }
}
