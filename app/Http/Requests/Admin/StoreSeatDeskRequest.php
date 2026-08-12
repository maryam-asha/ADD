<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Space;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class StoreSeatDeskRequest extends FormRequest
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
            'space_id' => ['required', 'integer', 'exists:spaces,id'],
            'label' => ['required', 'string', 'max:50'],
            'qr_point_id' => ['nullable', 'integer'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $spaceId = $this->input('space_id');

            if (! $spaceId) {
                return;
            }

            $space = Space::find($spaceId);

            if ($space && $space->space_type !== SpaceType::CoSpace) {
                $validator->errors()->add(
                    'space_id',
                    'A seat/desk can only be created inside a co_space.'
                );
            }
        });
    }
}
