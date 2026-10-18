<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Support\TranslatableField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * An open stage of the pipeline (between applied and offer, by sort).
 */
class StorePipelineStageRequest extends FormRequest
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
            'code' => ['required', 'string', 'alpha_dash', 'max:32', Rule::unique('hr_pipeline_stages', 'code')],
            ...TranslatableField::rules('name'),
            'sort' => ['required', 'integer', 'min:1', 'max:79'],
        ];
    }
}
