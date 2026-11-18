<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ErrorLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'error_type' => $this->error_type,
            'message' => $this->message,
            'stack_trace' => $this->stack_trace,
            'app_version' => $this->app_version,
            'build_number' => $this->build_number,
            'platform' => $this->platform,
            'os' => $this->os,
            'device' => $this->device,
            'screen' => $this->screen,
            'user_id' => $this->user_id,
            'session_id' => $this->session_id,
            'occurred_at' => $this->occurred_at,
            'metadata' => $this->metadata,
            'created_at' => $this->created_at,
        ];
    }
}
