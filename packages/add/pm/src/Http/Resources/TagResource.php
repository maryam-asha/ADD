<?php

namespace Add\Pm\Http\Resources;

use Add\Pm\Models\Tag;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A tag as the dashboard shows it: a name and the tone it is coloured in.
 */
class TagResource extends JsonResource
{
    /**
     * @return array{id: int, name: string, tone: string}
     */
    public function toArray(Request $request): array
    {
        /** @var Tag $tag */
        $tag = $this->resource;

        return ['id' => $tag->id, 'name' => $tag->name, 'tone' => $tag->tone->value];
    }
}
