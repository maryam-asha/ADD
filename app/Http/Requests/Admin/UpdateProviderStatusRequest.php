<?php

namespace App\Http\Requests\Admin;

use App\Domain\Providers\Enums\ProviderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProviderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::enum(ProviderStatus::class)]];
    }
}
