<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FounderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'role' => $this->role,
            'bio' => $this->bio,
            'photo_url' => $this->photo_url,
            'linkedin_url' => $this->linkedin_url,
            'twitter_url' => $this->twitter_url,
            'order' => $this->order,
        ];
    }
}
