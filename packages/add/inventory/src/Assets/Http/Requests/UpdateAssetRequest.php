<?php

namespace Add\Inventory\Assets\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Its details only. Where it is, who holds it and its status each have
 * their own action.
 */
class UpdateAssetRequest extends FormRequest
{
    use AssetDetailRules;

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
            'code' => ['sometimes', 'string', 'max:40', Rule::unique('inv_assets', 'code')->ignore($this->route('asset'))],
            ...$this->detailRules('sometimes'),
        ];
    }
}
