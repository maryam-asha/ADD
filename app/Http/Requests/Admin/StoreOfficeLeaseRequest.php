<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\Admin\Concerns\ValidatesLeaseTerms;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/** A lease for the existing company a quoted office request names. */
class StoreOfficeLeaseRequest extends FormRequest
{
    use ValidatesLeaseTerms;

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->leaseRules();
    }

    public function after(): array
    {
        return [fn (Validator $validator) => $this->validateLeaseTerms($validator)];
    }
}
