<?php

namespace App\Domain\Forms\Services;

use App\Domain\Forms\Models\Form;
use App\Domain\Forms\Models\FormAnswer;
use App\Domain\Forms\Models\FormSubmission;
use App\Domain\Identity\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;

/**
 * Stores a member's answers to a form against the record the submission
 * created. The one write path for answers, whichever model owns the form.
 */
class FormSubmissionService
{
    /**
     * Walks the form's own active fields rather than the submitted keys, so
     * an unknown, inactive or reserved key in the payload cannot create a
     * row. Validation has already run in the Form Request; the caller owns
     * the transaction.
     *
     * @param  array<string, mixed>  $values  Validated answers keyed by field key.
     */
    public function record(Form $form, Model $subject, array $values, ?User $by = null): FormSubmission
    {
        $submission = $form->submissions()->create([
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'user_id' => $by?->id,
            'submitted_at' => now(),
        ]);

        foreach ($form->activeFields as $field) {
            $value = $values[$field->key] ?? null;

            if ($value === null || $value === '') {
                continue;
            }

            if ($field->fieldType()->storesFile() && $value instanceof UploadedFile) {
                $value = $value->store("form-submissions/{$submission->id}", 'public');
            }

            FormAnswer::fromField($submission, $field, $value)->save();
        }

        return $submission;
    }
}
