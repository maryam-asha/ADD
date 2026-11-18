<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Models\Kiosk;
use App\Http\Requests\Admin\StoreKioskRequest;
use App\Http\Requests\Admin\UpdateKioskRequest;
use App\Http\Resources\KioskResource;
use Illuminate\Http\JsonResponse;

class KioskController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return Kiosk::class;
    }

    protected function resourceClass(): string
    {
        return KioskResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    public function store(StoreKioskRequest $request): KioskResource
    {
        return new KioskResource(Kiosk::create(array_merge(['is_active' => true], $request->validated())));
    }

    public function update(UpdateKioskRequest $request, Kiosk $kiosk): JsonResponse
    {
        $kiosk->update($request->validated());

        return response()->json(['message' => __('api.admin.kiosk_updated')]);
    }
}
