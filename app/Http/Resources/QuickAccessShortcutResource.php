<?php

namespace App\Http\Resources;

use App\Domain\Ecosystem\Models\QuickAccessShortcut;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard's view of a quick-access tile: the label in both
 * languages, since this is where it is edited.
 *
 * @property QuickAccessShortcut $resource
 */
class QuickAccessShortcutResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'icon_url' => $this->icon_url,
            'route' => $this->route,
            'order' => $this->order,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
