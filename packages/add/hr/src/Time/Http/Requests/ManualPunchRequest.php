<?php

namespace Add\Hr\Time\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * HR typing in a punch: local `Y-m-d H:i`, always with a reason.
 */
class ManualPunchRequest extends FormRequest
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
            'punched_at' => ['required', 'date_format:Y-m-d H:i'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
