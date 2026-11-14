<?php

namespace Add\Pm\Http\Requests;

use Add\Pm\Contracts\PeopleDirectory;
use Add\Pm\Enums\ProjectTone;
use Add\Pm\Models\Project;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a project (name and tone required) or editing one (only what is
 * sent). The lead must be on the team: the one sent with the project, or the
 * current one when editing.
 */
class SaveProjectRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $editing = $this->route('project') instanceof Project;
        $required = $editing ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:5000'],
            'tone' => [$required, Rule::enum(ProjectTone::class)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d'],
            'is_archived' => ['sometimes', 'boolean'],
            'lead_user_id' => ['nullable', 'integer'],
            // Members of an existing project change through their own route.
            'members' => [$editing ? 'prohibited' : 'sometimes', 'array'],
            'members.*' => ['integer', 'distinct', function (string $attribute, mixed $value, \Closure $fail) {
                if (! app(PeopleDirectory::class)->isPerson((int) $value)) {
                    $fail(__('validation.exists', ['attribute' => $attribute]));
                }
            }],
        ];
    }

    /**
     * @return list<\Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $project = $this->route('project');
            $existing = $project instanceof Project ? $project : null;

            // An edit that moves one end of the dates must still keep them in
            // order against the end it leaves alone.
            $start = $this->input('starts_on', $existing?->starts_on?->format('Y-m-d'));
            $end = $this->input('ends_on', $existing?->ends_on?->format('Y-m-d'));
            if ($start !== null && $end !== null && $end < $start) {
                $validator->errors()->add('ends_on', __('validation.after_or_equal', ['attribute' => 'ends on', 'date' => 'starts on']));
            }

            $lead = $this->input('lead_user_id');
            if ($lead !== null) {
                $members = $existing !== null ? $existing->memberIds() : array_map('intval', $this->input('members', []));
                if (! in_array((int) $lead, $members, true)) {
                    $validator->errors()->add('lead_user_id', __('pm::pm.lead_not_member'));
                }
            }
        }];
    }
}
