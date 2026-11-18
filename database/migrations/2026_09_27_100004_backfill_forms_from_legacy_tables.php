<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copies the per-domain form tables into the unified ones
 * (docs/decisions/unified-forms.md):
 *
 *   service_type_fields             → forms (service_type/request) + form_fields
 *   service_request_values          → form_submissions (service_request) + form_answers
 *   event_registration_fields       → forms (event/registration) + form_fields
 *   event_registration_field_values → form_submissions (event_attendee) + form_answers
 *
 * Query builder only, with the morph aliases and field types written out
 * as literals: a migration must keep meaning what it meant on the day it
 * ran, whatever the models and enums become later. The legacy tables are
 * read, never written — they stay as the rollback source until the release
 * that drops them.
 *
 * Everything happens in one transaction and is checked both ways: data
 * the new schema cannot represent aborts the copy before anything is
 * written, and a row count that does not reconcile afterwards rolls it
 * back.
 */
return new class extends Migration
{
    /** FieldType's values on the day this ran. */
    private const FIELD_TYPES = ['text', 'textarea', 'select', 'file', 'checkbox', 'date', 'number', 'rating'];

    private const KEY_MAX = 80;

    /**
     * One entry per legacy domain: where its fields and answers live, which
     * header table the answers hang off, and the aliases/purpose they map to.
     *
     * @var list<array<string, string>>
     */
    private const SOURCES = [
        [
            'fields' => 'service_type_fields',
            'owner_fk' => 'service_type_id',
            'owner_alias' => 'service_type',
            'purpose' => 'request',
            'values' => 'service_request_values',
            'value_field_fk' => 'service_type_field_id',
            'value_subject_fk' => 'service_request_id',
            'subjects' => 'service_requests',
            'subject_alias' => 'service_request',
            'subject_owner_fk' => 'service_type_id',
            'submitted_at' => 'submitted_at',
        ],
        [
            'fields' => 'event_registration_fields',
            'owner_fk' => 'event_id',
            'owner_alias' => 'event',
            'purpose' => 'registration',
            'values' => 'event_registration_field_values',
            'value_field_fk' => 'event_registration_field_id',
            'value_subject_fk' => 'event_attendee_id',
            'subjects' => 'event_attendees',
            'subject_alias' => 'event_attendee',
            'subject_owner_fk' => 'event_id',
            // Attendees have no submission timestamp of their own; the row
            // was created at the moment of registration.
            'submitted_at' => 'created_at',
        ],
    ];

    public function up(): void
    {
        DB::transaction(function () {
            $sources = array_values(array_filter(
                self::SOURCES,
                fn (array $s) => Schema::hasTable($s['fields']) && Schema::hasTable($s['values']),
            ));

            $this->assertTargetIsEmpty();

            foreach ($sources as $source) {
                $this->assertRepresentable($source);
            }

            foreach ($sources as $source) {
                [$formIds, $fieldIds] = $this->copyFields($source);
                $this->copyAnswers($source, $formIds, $fieldIds);
            }

            $this->assertReconciled($sources);
        });
    }

    /**
     * Empties the unified tables. Safe only before the new code has taken
     * any submissions — after that, rows exist here that the legacy tables
     * never had, and this would delete them.
     */
    public function down(): void
    {
        DB::transaction(function () {
            DB::table('form_answers')->delete();
            DB::table('form_submissions')->delete();
            DB::table('form_fields')->delete();
            DB::table('forms')->delete();
        });
    }

    private function assertTargetIsEmpty(): void
    {
        if (DB::table('forms')->exists() || DB::table('form_submissions')->exists()) {
            throw new RuntimeException('Unified form tables already hold data; refusing to backfill over it.');
        }
    }

    /**
     * @param  array<string, string>  $s
     */
    private function assertRepresentable(array $s): void
    {
        $fields = DB::table($s['fields'])->get(['id', 'key', 'type']);

        foreach ($fields as $field) {
            if (! in_array($field->type, self::FIELD_TYPES, true)) {
                throw new RuntimeException("{$s['fields']}#{$field->id} has unknown type \"{$field->type}\".");
            }

            if (mb_strlen((string) $field->key) > self::KEY_MAX) {
                throw new RuntimeException("{$s['fields']}#{$field->id} has a key longer than ".self::KEY_MAX.' characters.');
            }
        }

        // An answer filed against a field of a different owner than its
        // header's would land on a form it was never asked on.
        $misfiled = DB::table($s['values'].' as v')
            ->join($s['fields'].' as f', 'f.id', '=', 'v.'.$s['value_field_fk'])
            ->join($s['subjects'].' as h', 'h.id', '=', 'v.'.$s['value_subject_fk'])
            ->whereColumn('f.'.$s['owner_fk'], '!=', 'h.'.$s['subject_owner_fk'])
            ->count();

        if ($misfiled > 0) {
            throw new RuntimeException("{$s['values']} has {$misfiled} answer(s) whose field belongs to another owner than their {$s['subjects']} row.");
        }
    }

    /**
     * A form for every owner with at least one field — inactive ones
     * included — and every field copied onto it.
     *
     * @param  array<string, string>  $s
     * @return array{0: array<int, int>, 1: array<int, int>} owner id => form id, legacy field id => new field id
     */
    private function copyFields(array $s): array
    {
        $formIds = [];
        $fieldIds = [];

        foreach (DB::table($s['fields'])->orderBy('id')->get() as $field) {
            $ownerId = (int) $field->{$s['owner_fk']};

            $formIds[$ownerId] ??= DB::table('forms')->insertGetId([
                'formable_type' => $s['owner_alias'],
                'formable_id' => $ownerId,
                'purpose' => $s['purpose'],
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $fieldIds[(int) $field->id] = DB::table('form_fields')->insertGetId([
                'form_id' => $formIds[$ownerId],
                'key' => $field->key,
                'label' => $field->label,
                'placeholder' => $field->placeholder,
                'type' => $field->type,
                'options' => $field->options,
                'validation' => $field->validation,
                'is_required' => $field->is_required,
                'is_active' => $field->is_active,
                'order' => $field->order,
                'created_at' => $field->created_at,
                'updated_at' => $field->updated_at,
            ]);
        }

        return [$formIds, $fieldIds];
    }

    /**
     * One submission per header that has answers, and every answer on it
     * with the field snapshot taken. Value columns are copied as stored —
     * JSON as its raw text, file paths untouched, so no file moves.
     *
     * @param  array<string, string>  $s
     * @param  array<int, int>  $formIds
     * @param  array<int, int>  $fieldIds
     */
    private function copyAnswers(array $s, array $formIds, array $fieldIds): void
    {
        $submissionIds = [];

        DB::table($s['values'].' as v')
            ->join($s['fields'].' as f', 'f.id', '=', 'v.'.$s['value_field_fk'])
            ->join($s['subjects'].' as h', 'h.id', '=', 'v.'.$s['value_subject_fk'])
            ->select([
                'v.id', 'v.value_text', 'v.value_json', 'v.value_file', 'v.created_at', 'v.updated_at',
                'v.'.$s['value_field_fk'].' as legacy_field_id',
                'v.'.$s['value_subject_fk'].' as subject_id',
                'f.key as field_key', 'f.type as field_type', 'f.label as field_label',
                'h.'.$s['subject_owner_fk'].' as owner_id',
                'h.user_id as user_id',
                'h.'.$s['submitted_at'].' as submitted_at',
            ])
            ->chunkById(500, function ($rows) use ($s, $formIds, $fieldIds, &$submissionIds) {
                foreach ($rows as $row) {
                    $subjectId = (int) $row->subject_id;

                    $submissionIds[$subjectId] ??= DB::table('form_submissions')->insertGetId([
                        'form_id' => $formIds[(int) $row->owner_id],
                        'subject_type' => $s['subject_alias'],
                        'subject_id' => $subjectId,
                        'user_id' => $row->user_id,
                        'submitted_at' => $row->submitted_at ?? $row->created_at ?? now(),
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);

                    DB::table('form_answers')->insert([
                        'form_submission_id' => $submissionIds[$subjectId],
                        'form_field_id' => $fieldIds[(int) $row->legacy_field_id],
                        'field_key' => $row->field_key,
                        'field_type' => $row->field_type,
                        'field_label' => $row->field_label,
                        'value_text' => $row->value_text,
                        'value_json' => $row->value_json,
                        'value_file' => $row->value_file,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ]);
                }
            }, 'v.id', 'id');
    }

    /**
     * @param  list<array<string, string>>  $sources
     */
    private function assertReconciled(array $sources): void
    {
        $legacyFields = array_sum(array_map(fn ($s) => DB::table($s['fields'])->count(), $sources));
        $legacyValues = array_sum(array_map(fn ($s) => DB::table($s['values'])->count(), $sources));

        $fields = DB::table('form_fields')->count();
        $answers = DB::table('form_answers')->count();

        if ($fields !== $legacyFields || $answers !== $legacyValues) {
            throw new RuntimeException(
                "Backfill does not reconcile: {$fields}/{$legacyFields} fields, {$answers}/{$legacyValues} answers."
            );
        }
    }
};
