<?php

namespace App\Casts;

use App\Services\Media\ImageService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class AsMediaUrl implements CastsAttributes
{
    /**
     * Cast the given value when reading from the model.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return app(ImageService::class)->url((string) $value);
    }

    /**
     * Prepare the given value for storage in the database.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $imageService = app(ImageService::class);
        $valueStr = trim((string) $value);

        // If it's a 3rd-party external URL, store as-is
        if ($imageService->isExternalUrl($valueStr)) {
            return $valueStr;
        }

        // Otherwise, extract the clean relative storage path (e.g. 'uploads/xyz.webp')
        $storagePath = $imageService->toStoragePath($valueStr);

        return $storagePath ?? $valueStr;
    }
}
