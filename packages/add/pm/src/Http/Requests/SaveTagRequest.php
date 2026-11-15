<?php

namespace Add\Pm\Http\Requests;

use Add\Pm\Enums\ProjectTone;
use Add\Pm\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Creating a tag or editing one. Names are unique, because a label that
 * appears twice cannot be told apart on a card.
 */
class SaveTagRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $tag = $this->route('tag');
        $required = $tag instanceof Tag ? 'sometimes' : 'required';

        return [
            'name' => [$required, 'string', 'max:48', Rule::unique('pm_tags', 'name')->ignore($tag instanceof Tag ? $tag->id : null)],
            'tone' => [$required, Rule::enum(ProjectTone::class)],
        ];
    }
}
