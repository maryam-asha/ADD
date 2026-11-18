<?php

namespace Add\Hr\Benefits\Http\Requests;

use Add\Hr\Contracts\BenefitFulfiller;

/**
 * Adds the host's rules for an in-kind benefit to the package's own: a key
 * both name gets both lists, so the host narrows a field, never loosens it.
 */
final class FulfillerRules
{
    /**
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    public static function merge(array $rules): array
    {
        foreach (app(BenefitFulfiller::class)->rules() as $key => $extra) {
            $rules[$key] = [...(array) ($rules[$key] ?? []), ...(array) $extra];
        }

        return $rules;
    }
}
