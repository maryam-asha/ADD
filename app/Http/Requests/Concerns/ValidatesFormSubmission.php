<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Forms\Models\Form;
use App\Support\Forms\FieldRuleBuilder;
use App\Support\Forms\ReservedFieldKeys;
use Illuminate\Contracts\Validation\Validator;

/**
 * Validates `fields.<key>` against a form's own active questions, for any
 * Form Request that submits one — a service request, an event
 * registration, and whatever collects a form next. Rules and attribute
 * names are built from the stored definitions, so adding or changing a
 * question is a DB edit, never a code change.
 *
 * The using request supplies the form through resolveSubmittedForm(); a
 * null form means there are no questions, and `fields` is then only
 * structurally checked.
 */
trait ValidatesFormSubmission
{
    private ?Form $submittedForm = null;

    private bool $submittedFormResolved = false;

    /** The form this submission answers, with its active fields loaded, or null. */
    abstract protected function resolveSubmittedForm(): ?Form;

    /**
     * @return array<string, mixed>
     */
    protected function formFieldRules(): array
    {
        $base = ['fields' => ['nullable', 'array']];
        $form = $this->submittedForm();

        return $form === null
            ? $base
            : $base + app(FieldRuleBuilder::class)->rulesFor($form->activeFields);
    }

    /**
     * Name each generated rule path after the question the admin wrote, so a
     * failure reads "الاحتياجات الغذائية" rather than "fields.dietary_needs".
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        $form = $this->submittedForm();

        return $form === null ? [] : app(FieldRuleBuilder::class)->attributesFor($form->activeFields);
    }

    public function withValidator(Validator $validator): void
    {
        // Identity comes from the token. Say so plainly rather than
        // dropping these keys silently.
        $validator->after(function (Validator $validator) {
            foreach (ReservedFieldKeys::present((array) $this->input('fields', [])) as $key) {
                $validator->errors()->add(
                    "fields.{$key}",
                    __('api.validation.reserved_field_key', ['key' => $key])
                );
            }
        });
    }

    /**
     * The validated answers keyed by field key. From validated() rather than
     * input(): input() leaves uploaded files out, which silently dropped
     * every file answer on service requests.
     *
     * @return array<string, mixed>
     */
    public function formFieldValues(): array
    {
        return (array) $this->validated('fields', []);
    }

    public function submittedForm(): ?Form
    {
        if (! $this->submittedFormResolved) {
            $this->submittedForm = $this->resolveSubmittedForm()?->loadMissing('activeFields');
            $this->submittedFormResolved = true;
        }

        return $this->submittedForm;
    }
}
