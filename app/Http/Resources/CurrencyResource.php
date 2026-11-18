<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CurrencyResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $headerLang = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language') ?: app()->getLocale()));
        $locale = str_contains($headerLang, 'en') ? 'en' : (str_contains($headerLang, 'ar') ? 'ar' : app()->getLocale());

        return [
            'code' => $this->code,
            'name' => $this->translate('name', $locale),
            'symbol' => $this->symbol,
        ];
    }
}
