<?php

namespace App\Http\Resources\Services;

use App\Domain\Services\Models\ServiceType;
use App\Http\Resources\Forms\FormFieldResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ServiceType */
class ServiceTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->translate('name', $locale),
            'description' => $this->translate('description', $locale),
            'icon' => $this->icon,
            'image_url' => $this->image_url,
            // The "Explore ADD" page's explanation, HTML in the requested
            // language: only on the single-service views (they load the
            // request form), so a list does not carry every page.
            'about' => $this->when($this->resource->relationLoaded('requestForm'), fn () => $this->translate('about', $locale)),
            'is_active' => (bool) $this->is_active,
            'is_coming_soon' => (bool) $this->is_coming_soon,
            'show_on_home' => (bool) $this->show_on_home,
            'status' => $this->computedStatus(),
            'can_request' => $this->is_active && ! $this->is_coming_soon,
            'request_method' => $this->request_method->value,
            // What the method needs to act — the route an internal_redirect
            // opens, the number a phone call dials, the link, the text — on
            // the single-service views alone, like `about`: the catalog's
            // lists keep their slim contract.
            'request_config' => $this->when($this->resource->relationLoaded('requestForm'), fn () => $this->request_config),
            'axis' => $this->whenLoaded('axis', fn () => [
                'id' => $this->axis->id,
                'slug' => $this->axis->slug,
                'name' => $this->axis->translate('name', $locale),
                'color' => $this->axis->color,
            ]),
            // Form fields: only on the single-service views, which load the
            // request form. `[]` when the type asks nothing.
            'registration_form' => $this->whenLoaded('requestForm', fn () => $this->formFields()),
            'fields' => $this->whenLoaded('requestForm', fn () => $this->formFields()),
        ];
    }

    private function formFields(): mixed
    {
        return FormFieldResource::collection($this->requestForm?->activeFields ?? collect());
    }

    private function computedStatus(): string
    {
        if (! $this->is_active) {
            return 'unavailable';
        }

        if ($this->is_coming_soon) {
            return 'coming_soon';
        }

        return 'open';
    }
}
