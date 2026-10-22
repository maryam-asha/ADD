<?php

namespace Add\Hr\Core\Http\Requests;

use Add\Hr\Core\Enums\ChecklistAssignee;
use Add\Hr\Core\Enums\ChecklistKind;
use Add\Hr\Core\Models\ChecklistTemplate;
use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreChecklistTemplateRequest extends FormRequest
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
            'kind' => ['required', Rule::enum(ChecklistKind::class)],
            ...TranslatableField::rules('name'),
            'items' => ['required', 'array', 'min:1', 'max:50'],
            ...TranslatableField::rules('items.*.title'),
            'items.*.due_offset_days' => ['required', 'integer', 'min:-365', 'max:365'],
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
            $items = (array) $this->input('items', []);

            foreach (ChecklistTemplate::employeeItemsAfterLastDay(ChecklistKind::tryFrom((string) $this->input('kind')), $items) as $index) {
                $validator->errors()->add("items.{$index}.due_offset_days", __('hr::core.employee_task_after_last_day'));
            }
        }];
    }
}
