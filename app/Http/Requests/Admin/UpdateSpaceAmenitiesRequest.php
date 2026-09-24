<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\AmenityType;
use App\Domain\Foundation\Models\Space;
use App\Domain\Foundation\Models\SpaceTypeAmenity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSpaceAmenitiesRequest extends FormRequest
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
            // clear every amenity this space has turned on.
            'amenities' => ['present', 'array'],
            'amenities.*' => [Rule::enum(AmenityType::class)],
        ];
    }

    /**
     * Cross-checks submitted amenities against the target space's own
     * `space_type` config (SpaceTypeAmenity) — an amenity that's a valid
     * AmenityType value in general but not configured as applicable to
     * this space's space_type is still rejected. Same check
     * StoreSpaceRequest runs, but keyed off the route-bound Space's
     * space_type rather than a `space_type` field in the request body —
     * this endpoint only ever touches `amenities`.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $amenities = $this->input('amenities');

            if (empty($amenities) || ! is_array($amenities)) {
                return;
            }

            /** @var Space $space */
            $space = $this->route('space');

            $allowed = SpaceTypeAmenity::query()
                ->where('space_type', $space->space_type)
                ->get()
                ->map(fn (SpaceTypeAmenity $row) => $row->amenity->value)
                ->all();

            foreach ($amenities as $amenity) {
                if (! in_array($amenity, $allowed, true)) {
                    $validator->errors()->add(
                        'amenities',
                        __('api.validation.amenity_not_available_for_space_type', ['amenity' => (string) $amenity])
                    );
                }
            }
        });
    }
}
