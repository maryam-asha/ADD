<?php

namespace Add\Pm\Http\Requests;

use Add\Pm\Enums\TaskStatus;
use Add\Pm\Models\Task;
use Add\Pm\Services\ProjectAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Dropping a card on the board: the column it lands in and, optionally, the
 * ids of that column in the order the person left it. Someone who cannot see
 * the project gets 404 before validation, as for every task route.
 */
class MoveTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $task = $this->route('task');
        abort_unless($task instanceof Task && app(ProjectAccess::class)->sees((int) $this->user()->getAuthIdentifier(), $task->project), 404);

        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::enum(TaskStatus::class)],
            'order' => ['sometimes', 'array', 'max:500'],
            'order.*' => ['integer', 'distinct'],
        ];
    }
}
