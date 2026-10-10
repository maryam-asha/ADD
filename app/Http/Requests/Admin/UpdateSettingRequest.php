<?php

namespace App\Http\Requests\Admin;

use App\Domain\Finance\Services\PointConversionService;
use App\Domain\Membership\Services\TopUpMinimum;
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
        if ($this->targetSetting()->key === 'app.timezone') {
            return ['value' => ['required', 'string', 'timezone']];
        }

        if ($this->targetSetting()->key === PointConversionService::SETTING_KEY) {
            // `numeric|decimal:0,4` alone also lets `.05`, `5.` and `+0.10`
            // through; the regex keeps the stored string a plain decimal.
            return ['value' => ['required', 'numeric', 'gt:0', 'decimal:0,4', 'regex:/^\d+(\.\d{1,4})?$/']];
        }

        if ($this->targetSetting()->key === TopUpMinimum::SETTING_KEY) {
            return ['value' => ['required', 'integer', 'min:1']];
        }

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
            ->where('scope_id', 0)
            ->firstOrFail();
    }
}
