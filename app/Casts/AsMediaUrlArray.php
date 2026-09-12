<?php

namespace App\Casts;

use App\Services\Media\ImageService;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

class AsMediaUrlArray implements CastsAttributes
{
    /**
     * Cast the given value when reading from the model.
     *
     * @param  array<string, mixed>  $attributes
     * @return list<string>|null
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?array
    {
        if ($value === null) {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($decoded)) {
            return null;
        }

        $imageService = app(ImageService::class);

        return array_values(array_map(function ($item) use ($imageService) {
            return $imageService->url((string) $item);
        }, $decoded));
    }

    /**
     * Prepare the given value for storage in the database.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $list = is_string($value) ? json_decode($value, true) : $value;
        if (! is_array($list)) {
            return null;
        }

        $imageService = app(ImageService::class);
        $cleanList = [];

        foreach ($list as $item) {
            if ($item === null || trim((string) $item) === '') {
                continue;
            }
            $itemStr = trim((string) $item);
            if ($imageService->isExternalUrl($itemStr)) {
                $cleanList[] = $itemStr;
            } else {
                $cleanList[] = $imageService->toStoragePath($itemStr) ?? $itemStr;
            }
        }

        return json_encode(array_values($cleanList));
    }
}
