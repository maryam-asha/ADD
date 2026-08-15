<?php

namespace App\Http\Requests\Admin;

use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Enums\SettingValueType;
use App\Domain\Settings\Models\Setting;
use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingRequest extends FormRequest
{
    private ?Setting $resolvedSetting = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'value' => match ($this->targetSetting()->type) {
                SettingValueType::Int => ['required', 'integer'],
                SettingValueType::Bool => ['required', 'boolean'],
                SettingValueType::String => ['required', 'string'],
                SettingValueType::Time => ['required', 'date_format:H:i'],
                SettingValueType::Json => ['required', 'array'],
            },
        ];
    }

    public function targetSetting(): Setting
    {
        return $this->resolvedSetting ??= Setting::query()
            ->where('key', $this->route('key'))
            ->where('scope_type', SettingScope::Global)
            ->firstOrFail();
    }
}
