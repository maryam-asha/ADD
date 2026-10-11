<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\AmenityType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpaceTypeAmenitiesRequest extends FormRequest
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
            // `present`, not `required`: an empty array is the valid way to
            // clear every amenity configured for this space type.
            'amenities' => ['present', 'array'],
            'amenities.*' => [Rule::enum(AmenityType::class)],
        ];
    }
}
