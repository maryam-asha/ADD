<?php

namespace App\Http\Requests\Services\Admin;

use App\Domain\Services\Enums\RequestStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateServiceRequestStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $allowed = collect(RequestStatus::operationsAllowed())->map->value->all();

        return [
            'status'              => ['required', 'string', Rule::in($allowed)],
            'resolution_note'     => ['nullable', 'array'],
            'resolution_note.en'  => ['nullable', 'string', 'max:1000'],
            'resolution_note.ar'  => ['nullable', 'string', 'max:1000'],
        ];
    }
}
