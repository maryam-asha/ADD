<?php

namespace App\Http\Requests\Admin;

use App\Domain\Identity\Enums\PrivateOfficeRequestStatus;
use App\Domain\Identity\Models\PrivateOfficeRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Companies are created exclusively by operations after a contract is
 * signed (PRD §5.1) — the one path into `companies`. The request must
 * currently be `quoted`: the pipeline is request -> quote -> signed
 * contract (§5.3), and skipping straight from `requested` would presume a
 * quote that was never issued.
 */
class StoreCompanyRequest extends FormRequest
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
            'private_office_request_id' => ['required', 'integer', 'exists:private_office_requests,id'],
            'legal_name' => ['required', 'string', 'max:255'],
            'contract_ref' => ['required', 'string', 'max:255'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $requestId = $this->input('private_office_request_id');

            if (! $requestId) {
                return;
            }

            $poRequest = PrivateOfficeRequest::find($requestId);

            if ($poRequest && $poRequest->status !== PrivateOfficeRequestStatus::Quoted) {
                $validator->errors()->add(
                    'private_office_request_id',
                    'This request must be quoted before a company can be created from it.'
                );
            }
        });
    }
}
