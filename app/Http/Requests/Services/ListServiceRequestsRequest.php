<?php

namespace App\Http\Requests\Services;

use App\Domain\Services\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `per_page` is deliberately not validated here: the listing clamps it to
 * 1..100 like the other member listings, rather than refusing the request.
 */
class ListServiceRequestsRequest extends FormRequest
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
            'status' => ['nullable', Rule::enum(RequestStatus::class)],
        ];
    }
}
