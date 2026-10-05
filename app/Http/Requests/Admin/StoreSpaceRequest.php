<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Enums\AllocationModel;
use App\Domain\Foundation\Enums\SpaceType;
use App\Domain\Foundation\Models\Building;
use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Http\Requests\Concerns\InterpretsLocalTimes;
use App\Http\Requests\Concerns\ValidatesSaleWindow;
use App\Support\TranslatableField;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSpaceRequest extends FormRequest
{
    use InterpretsLocalTimes, ValidatesSaleWindow;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('image_url') && ! $this->has('images') && $this->input('image_url') !== null) {
            $this->merge([
                'images' => [$this->input('image_url')],
            ]);
        }

        $this->interpretSaleWindow();
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return array_merge(
            TranslatableField::rules('name'),
            [
                'building_id' => ['required', 'integer', 'exists:buildings,id'],
                'zone_id' => ['nullable', 'integer', 'exists:zones,id'],
                'space_type' => ['required', Rule::enum(SpaceType::class)],
                'allocation_model' => ['nullable', Rule::enum(AllocationModel::class)],
                'is_lockable' => ['required', 'boolean'],
                // A co_space's capacity is the number of its desks, kept by
                // SeatDesk — never typed in (docs/decisions/coworking-seat-pool.md).
                'capacity' => $this->input('space_type') === SpaceType::CoSpace->value
                    ? ['prohibited']
                    : ['nullable', 'integer', 'min:0'],
                'images' => ['nullable', 'array'],
                'images.*' => ['string', 'max:2048'],
                'image_url' => ['nullable', 'string', 'max:2048'],
                'hourly_rate_points' => ['nullable', 'integer', 'min:0'],
                // Booking rules set per space
                // (docs/decisions/booking-outcomes-and-extension.md). Left
                // out, a space keeps what it has; a null window falls back
                // to the global booking.cancellation_window_minutes.
                'allows_extension' => ['sometimes', 'boolean'],
                'cancellation_window_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10080'],
            ],
            // An hourly rate on sale for a while (a week at 80 instead of 100).
            $this->saleWindowRules('sale_hourly_rate_points'),
        );
    }

    /**
     * A branch's co-working seats are the desks of its one co_space, so a
     * second one — created, or a space turned into one — is refused; more
     * seats are more desks. And a co_space with desks keeps its type: turned
     * into anything else, its desks would sit in a space that has none
     * (docs/decisions/coworking-seat-pool.md). SpaceController re-checks the
     * first rule under the branch lock. A sale rate is below the hourly rate.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $self = $this->route('space');
            $self = $self instanceof Space ? $self : ($self !== null ? Space::find($self) : null);

            $this->assertSaleBelowPrice($validator, 'sale_hourly_rate_points', 'hourly_rate_points', $self);

            if ($self !== null
                && $self->space_type === SpaceType::CoSpace
                && $this->input('space_type') !== SpaceType::CoSpace->value
                && SeatDesk::query()->where('space_id', $self->id)->exists()) {
                $validator->errors()->add('space_type', __('api.admin.co_space_has_desks'));

                return;
            }

            if ($this->input('space_type') !== SpaceType::CoSpace->value || ! $this->filled('building_id')) {
                return;
            }

            $branchId = Building::query()->whereKey($this->input('building_id'))->value('branch_id');

            if ($branchId !== null && Space::branchHasCoSpace((int) $branchId, $self?->id)) {
                $validator->errors()->add('space_type', __('api.admin.co_space_exists'));
            }
        });
    }
}
