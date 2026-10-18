<?php

namespace Add\Hr\Recruitment\Http\Requests;

use Add\Hr\Recruitment\Enums\CandidateSource;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * HR adds a candidate to an opening: an existing candidate by id, or a new person (one per email).
 */
class StoreApplicationRequest extends FormRequest
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
            'candidate_id' => ['nullable', 'integer', Rule::exists('hr_candidates', 'id')],
            'name' => ['required_without:candidate_id', 'nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:190'],
            'phone' => ['nullable', 'string', 'max:32'],
            'source' => ['required', Rule::in([CandidateSource::Referral->value, CandidateSource::Agency->value, CandidateSource::Direct->value])],
            'referred_by_employee_id' => ['nullable', 'required_if:source,referral', 'integer', Rule::exists('hr_employees', 'id')],
            'cover_letter' => ['nullable', 'string', 'max:5000'],
            'cv' => ['nullable', 'file', 'mimes:'.implode(',', (array) config('hr.recruitment.cv_mimes', ['pdf', 'doc', 'docx'])), 'max:'.(int) config('hr.recruitment.cv_max_kb', 5120)],
        ];
    }
}
