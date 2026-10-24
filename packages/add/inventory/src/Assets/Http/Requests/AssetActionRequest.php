<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Assets\Enums\AssetStatus;
use Add\Inventory\Assets\Enums\DisposalMethod;
use Add\Inventory\Support\Rules\PlaceRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one-step actions on an asset, each validated by the route it serves
 * (inventory.assets.move, .assign, ...): small enough that a class each
 * would only repeat this.
 */
class AssetActionRequest extends FormRequest
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
        $note = ['note' => ['nullable', 'string', 'max:2000']];
        $past = fn (string $field) => [$field => ['nullable', 'date', 'before_or_equal:now']];

        return match ($this->route()->getName()) {
            'inventory.assets.status' => [
                'status' => ['required', Rule::in(array_map(fn (AssetStatus $status) => $status->value, AssetStatus::settable()))],
            ],
            'inventory.assets.move' => [...PlaceRules::location(), ...$note, ...$past('moved_at')],
            'inventory.assets.assign' => [...PlaceRules::custodian(), ...$note, ...$past('assigned_at')],
            'inventory.assets.return' => [...$note, ...$past('returned_at')],
            'inventory.assets.dispose' => [
                'method' => ['required', Rule::enum(DisposalMethod::class)],
                'disposed_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
                'proceeds' => ['nullable', 'numeric', 'min:0', 'prohibited_unless:method,sold'],
                ...$note,
            ],
            default => [],
        };
    }
}
