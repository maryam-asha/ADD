<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;

/**
 * Weights are fixed here, not database-editable (2026-08-17 decision
 * session) — see
 * docs/decisions/profile-fields-completion-score-contact-links.md. Baseline
 * (name + verified phone, always true by the time a User row exists — see
 * decision doc) is a flat 25; the remaining 75 is earned per filled field,
 * computed fresh on every call rather than cached, so it can never drift
 * out of sync with the profile rows it reads.
 */
class ProfileCompletionService
{
    private const BASELINE_SCORE = 25;

    /** @var array<string, int> field key => weight, summing to 75 */
    private const WEIGHTS = [
        'avatar_url' => 15,
        'job_title' => 10,
        'bio' => 10,
        'city' => 10,
        'industry' => 7,
        'company_name' => 6,
        'linkedin_url' => 5,
        'instagram_url' => 4,
        'behance_url' => 3,
        'gender' => 3,
        'website_url' => 2,
    ];

    /** @var list<string> fields that live on UserPersonalProfile rather than UserProfessionalProfile */
    private const PERSONAL_FIELDS = ['avatar_url', 'bio', 'city', 'gender'];

    public function score(User $user): int
    {
        $earned = 0;

        foreach (self::WEIGHTS as $field => $weight) {
            if ($this->fieldValue($user, $field) !== null) {
                $earned += $weight;
            }
        }

        return self::BASELINE_SCORE + $earned;
    }

    /**
     * @return list<string>
     */
    public function missingFields(User $user): array
    {
        $missing = [];

        foreach (array_keys(self::WEIGHTS) as $field) {
            if ($this->fieldValue($user, $field) === null) {
                $missing[] = $field;
            }
        }

        return $missing;
    }

    private function fieldValue(User $user, string $field): mixed
    {
        if (in_array($field, self::PERSONAL_FIELDS, true)) {
            return $user->personalProfile?->{$field};
        }

        return $user->professionalProfile?->{$field};
    }
}
