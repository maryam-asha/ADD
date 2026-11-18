<?php

namespace App\Http\Requests\Admin;

use App\Domain\Foundation\Models\SeatDesk;
use App\Domain\Foundation\Models\Space;
use App\Domain\Kitchen\Enums\QrPointPlace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The desk or space a QR point is made for; it must exist.
 */
class StoreQrPointRequest extends FormRequest
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
        $table = match ($this->input('place_type')) {
            QrPointPlace::SeatDesk->value => (new SeatDesk)->getTable(),
            QrPointPlace::Space->value => (new Space)->getTable(),
            default => null,
        };

        return [
            'place_type' => ['required', Rule::enum(QrPointPlace::class)],
            'place_id' => array_filter(['required', 'integer', $table ? Rule::exists($table, 'id') : null]),
        ];
    }
}
