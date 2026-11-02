<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Models\ChecklistTemplate;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The kind is fixed once created; items are replaced as a whole list.
 */
class UpdateChecklistTemplateRequest extends FormRequest
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
        return [
            ...TranslatableField::sometimes('name'),
            'items' => ['sometimes', 'array', 'min:1', 'max:50'],
            ...TranslatableField::rules('items.*.title'),
            'items.*.due_offset_days' => ['required_with:items', 'integer', 'min:-365', 'max:365'],
            'items.*.assignee' => ['sometimes', Rule::enum(ChecklistAssignee::class)],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            /** @var ChecklistTemplate $template */
            $template = $this->route('checklistTemplate');
            $items = (array) $this->input('items', []);

            foreach (ChecklistTemplate::employeeItemsAfterLastDay($template->kind, $items) as $index) {
                $validator->errors()->add("items.{$index}.due_offset_days", __('hr::core.employee_task_after_last_day'));
            }
        }];
    }
}
