<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserProfileLinksResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'linkedin_url' => $this->linkedin_url,
            'instagram_url' => $this->instagram_url,
            'behance_url' => $this->behance_url,
            'website_url' => $this->website_url,
        ];
    }
}
