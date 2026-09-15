<?php

namespace App\Http\Resources;

use App\Domain\Experience\Services\EventLocationService;
use App\Domain\Finance\Services\CurrencyResolver;
use App\Support\Presenters\EventPricePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EventResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $isAdmin = $request->is('api/v1/admin/*');
        $header = strtolower((string) ($request->header('lang') ?: $request->header('Accept-Language')));
        $locale = str_contains($header, 'en') ? 'en' : (str_contains($header, 'ar') ? 'ar' : app()->getLocale());

        $user = null;
        try {
            $user = $request->user('sanctum');
        } catch (\Throwable) {
        }

        // Points are the stored price; every currency figure is derived at
        // read time (point system Invariant 1). The presenter also returns
        // display-ready labels so clients need not re-format them.
        $targetCurrency = app(CurrencyResolver::class)->resolve($request, $user);
        $price = app(EventPricePresenter::class)->present($this->resource, $targetCurrency, $locale);

        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'space_id' => $this->space_id,
            'category_id' => $this->category_id,
            'title' => $isAdmin ? $this->title : $this->translate('title', $locale),
            'description' => $isAdmin ? $this->description : $this->translate('description', $locale),
            'category' => $this->category ? ($isAdmin ? new EventCategoryResource($this->category) : [
                'id' => $this->category->id,
                'name' => $this->category->translate('name', $locale),
            ]) : null,
            'start_at' => $isAdmin ? $this->start_at?->toISOString() : $this->start_at?->format('Y-m-d'),
            'end_at' => $isAdmin ? $this->end_at?->toISOString() : $this->end_at?->format('Y-m-d'),
            'location' => app(EventLocationService::class)->displayLocation($this->resource, $locale),
            'capacity' => $this->capacity,
            'is_free' => (bool) ($this->is_free ?? true),
            ...$price,
            'display_currency' => $targetCurrency,
            'image_url' => $this->image_url,
            'status' => $this->status,
            'order' => $this->order,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
