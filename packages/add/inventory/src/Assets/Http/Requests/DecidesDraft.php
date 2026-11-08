<?php

namespace Add\Inventory\Assets\Http\Requests;

use Add\Inventory\Authorization\Abilities;

/**
 * config inventory.assets.drafts: with it on, a registration is a draft
 * unless `draft: false` is sent — and saving straight into service then
 * needs inventory.assets.approve. With it off, `draft` is not taken and
 * every registration goes into service.
 */
trait DecidesDraft
{
    public function authorize(): bool
    {
        return $this->wantsDraft() || ! config('inventory.assets.drafts') || (bool) $this->user()?->can(Abilities::ASSETS_APPROVE);
    }

    public function wantsDraft(): bool
    {
        return (bool) config('inventory.assets.drafts') && $this->boolean('draft', true);
    }

    /**
     * @return array<string, list<string>>
     */
    protected function draftRules(): array
    {
        return ['draft' => config('inventory.assets.drafts') ? ['sometimes', 'boolean'] : ['prohibited']];
    }
}
