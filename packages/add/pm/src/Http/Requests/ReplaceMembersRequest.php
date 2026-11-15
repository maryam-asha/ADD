<?php

namespace Add\Pm\Http\Requests;

use Add\Pm\Contracts\PeopleDirectory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The whole team of a project, as user ids. Route middleware has already
 * judged the ability.
 */
class ReplaceMembersRequest extends FormRequest
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
        return [
            'members' => ['present', 'array'],
            'members.*' => ['integer', 'distinct', function (string $attribute, mixed $value, \Closure $fail) {
                if (! app(PeopleDirectory::class)->isPerson((int) $value)) {
                    $fail(__('pm::pm.person_unavailable'));
                }
            }],
        ];
    }
}
