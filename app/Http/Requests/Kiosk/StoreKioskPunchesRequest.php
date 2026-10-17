<?php

namespace App\Http\Requests\Kiosk;

use App\Domain\Booking\Models\Kiosk;
use App\Domain\Hr\Kiosk\KioskRequestSignature;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * A batch of staff card scans: one live scan, or a kiosk's offline queue.
 * The signature is checked before anything else, so an unsigned caller
 * learns nothing about the body's shape.
 */
class StoreKioskPunchesRequest extends FormRequest
{
    private ?Kiosk $kiosk = null;

    public function authorize(KioskRequestSignature $signature): bool
    {
        $this->kiosk = $signature->verify($this);

        return $this->kiosk !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'punches' => ['required', 'array', 'min:1', 'max:100'],
            'punches.*.id' => ['required', 'uuid', 'distinct'],
            'punches.*.card' => ['required', 'string', 'max:200'],
            // An instant from the kiosk's clock, always with its offset
            // (2026-03-02T09:58:00+03:00): never a bare wall-clock time.
            'punches.*.scanned_at' => ['required', 'date', 'regex:/(Z|[+-]\d{2}:?\d{2})$/'],
        ];
    }

    public function kiosk(): Kiosk
    {
        return $this->kiosk;
    }

    protected function failedAuthorization(): never
    {
        throw new HttpResponseException(response()->json(['message' => __('api.hr.kiosk_unauthorized')], 401));
    }
}
