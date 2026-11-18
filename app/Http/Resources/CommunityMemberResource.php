<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CommunityMemberResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'category' => $this->category,
            'company' => $this->company,
            'title' => $this->title,
            'bio' => $this->bio,
            'long_bio' => $this->long_bio,
            'location' => $this->location,
            'year_joined' => $this->year_joined,
            'photo_url' => $this->photo_url,
            'social_links' => $this->social_links,
            'skills' => $this->skills,
            'order' => $this->order,
            'published' => $this->published,
        ];
    }
}
