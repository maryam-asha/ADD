<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            'email' => $this->email,
            'preferred_language' => $this->preferred_language,
            'preferred_currency' => $this->preferred_currency,
            'status' => $this->status,
            'roles' => $this->getRoleNames(),
        ];
    }
}
