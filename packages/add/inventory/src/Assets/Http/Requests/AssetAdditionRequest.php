<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Models\Asset;
use Add\Inventory\Inventory;
use Add\Inventory\Support\Rules\FileUrl;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;

/**
 * A capital addition on an asset: dated within the asset's life so far
 * (from when it was put in service, or bought, up to today).
 */
class AssetAdditionRequest extends FormRequest
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
            'added_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:'.Inventory::today()->toDateString()],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'description' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:60'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['required', ...FileUrl::rules()],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var Asset $asset */
            $asset = $this->route('asset');
            $start = ($asset->in_service_on ?? $asset->purchased_on)?->toDateString();
            $addedOn = $this->input('added_on');

            if ($start !== null && is_string($addedOn) && $addedOn < $start && ! $validator->errors()->has('added_on')) {
                $validator->errors()->add('added_on', __('inventory::assets.addition_before_start'));
            }

            // A month whose depreciation is closed is booked: nothing joins it.
            $closed = DB::table('inv_sequences')->where('name', 'depreciation.closed')->value('value');

            if ($closed !== null && is_string($addedOn) && (int) str_replace('-', '', substr($addedOn, 0, 7)) <= (int) $closed && ! $validator->errors()->has('added_on')) {
                $validator->errors()->add('added_on', __('inventory::assets.addition_in_closed_month'));
            }
        });
    }
}
