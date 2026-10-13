<?php

namespace App\Http\Resources;

use App\Domain\Ecosystem\Models\Announcement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard's view of an announcement: the text fields whole, both
 * languages, since this is where they are edited.
 *
 * @property Announcement $resource
 */
class AnnouncementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'type_label' => $this->type->label(),
            'placement' => $this->placement->value,
            'subtitle' => $this->subtitle,
            'title' => $this->title,
            'description' => $this->description,
            'cta_label' => $this->cta_label,
            'image_url' => $this->image_url,
            'link_url' => $this->link_url,
            'sort_order' => $this->sort_order,
            'starts_at' => $this->starts_at,
            'ends_at' => $this->ends_at,
            'is_active' => $this->is_active,
            'created_at' => $this->created_at,
        ];
    }
}
