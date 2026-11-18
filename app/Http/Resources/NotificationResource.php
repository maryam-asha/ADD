<?php

namespace App\Http\Resources;

use App\Services\Notification\NotificationText;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One inbox row, its title and body in the request's language: rendered
 * from its template and params, picked from a broadcast's per-locale text,
 * or — for a row written before params were kept, or whose template is
 * gone — the text it was stored with.
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->data['template_key'] ?? null,
            ...$this->text(app()->getLocale()),
            'data' => $this->data['data'] ?? null,
            'read_at' => $this->read_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{title: ?string, body: ?string}
     */
    private function text(string $locale): array
    {
        $key = $this->data['template_key'] ?? null;

        if ($key !== null && array_key_exists('params', $this->data) && NotificationText::exists($key)) {
            return NotificationText::render($key, (array) $this->data['params'], $locale);
        }

        $title = $this->data['title'] ?? null;
        $body = $this->data['body'] ?? null;

        return [
            'title' => is_array($title) ? NotificationText::pick($title, $locale) : $title,
            'body' => is_array($body) ? NotificationText::pick($body, $locale) : $body,
        ];
    }
}
