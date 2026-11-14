<?php

namespace Add\Pm\Http\Requests;

use Add\Pm\Enums\TaskPriority;
use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Project;
use Add\Pm\Models\Task;
use Add\Pm\Services\ProjectAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Creating a task on a project (title required) or editing one (only what is
 * sent). Who may write is the service's call; here a person who cannot even
 * see the project is turned away with 404 before anything is validated, so
 * the validation errors never reveal that it exists.
 */
class SaveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        abort_unless(app(ProjectAccess::class)->sees((int) $this->user()->getAuthIdentifier(), $this->project()), 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $required = $this->existing() !== null ? 'sometimes' : 'required';

        return [
            'title' => [$required, 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:10000'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
            'priority' => ['nullable', Rule::enum(TaskPriority::class)],
            'starts_on' => ['nullable', 'date_format:Y-m-d'],
            'due_on' => ['nullable', 'date_format:Y-m-d'],
            'estimate_hours' => ['nullable', 'numeric', 'min:0', 'max:999'],
            'parent_id' => ['nullable', 'integer'],
            'assignee_ids' => ['sometimes', 'array'],
            'assignee_ids.*' => ['integer', 'distinct'],
            'tag_ids' => ['sometimes', 'array'],
            'tag_ids.*' => ['integer', 'distinct', Rule::exists('pm_tags', 'id')],
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

            $existing = $this->existing();
            $project = $this->project();

            // An edit that moves one end must still keep the dates in order
            // against the end it leaves alone.
            $start = $this->has('starts_on') ? $this->input('starts_on') : $existing?->starts_on?->format('Y-m-d');
            $due = $this->has('due_on') ? $this->input('due_on') : $existing?->due_on?->format('Y-m-d');
            if ($start !== null && $due !== null && $due < $start) {
                $validator->errors()->add('due_on', __('pm::pm.due_before_start', [
                    'due' => __('pm::pm.fields.due_on'),
                    'start' => __('pm::pm.fields.starts_on'),
                ]));
            }

            $members = $project->memberIds();
            foreach ((array) $this->input('assignee_ids', []) as $index => $userId) {
                if (! in_array((int) $userId, $members, true)) {
                    $validator->errors()->add("assignee_ids.$index", __('pm::pm.assignee_not_member'));
                }
            }

            if ($this->filled('parent_id')) {
                $this->checkParent($validator, $project, $existing, (int) $this->input('parent_id'));
            }
        }];
    }

    /**
     * One level of subtasks: the parent is a top-level task of this project,
     * and a task that has subtasks cannot become one itself.
     */
    private function checkParent(Validator $validator, Project $project, ?Task $existing, int $parentId): void
    {
        $parent = Task::query()->where('project_id', $project->id)->find($parentId);

        if ($parent === null || ($existing !== null && $existing->id === $parent->id)) {
            $validator->errors()->add('parent_id', __('pm::pm.subtask_depth'));
        } elseif ($parent->parent_id !== null || ($existing !== null && $existing->subtasks()->exists())) {
            $validator->errors()->add('parent_id', __('pm::pm.subtask_depth'));
        }
    }

    private function existing(): ?Task
    {
        $task = $this->route('task');

        return $task instanceof Task ? $task : null;
    }

    private function project(): Project
    {
        $project = $this->route('project');

        return $project instanceof Project ? $project : $this->existing()->project;
    }
}
