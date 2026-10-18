<?php

namespace App\Domain\Identity\Services;

use App\Domain\Identity\Models\User;
use App\Domain\Identity\Models\UserSensitiveProfile;
use Illuminate\Support\Arr;

/**
 * The one way in to user_sensitive_profiles. Callers check who may see a
 * value (HR: hr.employees.view_sensitive, audited on its own side); every
 * change is logged here with the fields it touched, never their values.
 */
class SensitiveProfileService
{
    /** @var array<int, ?UserSensitiveProfile> per-request reads, by user id */
    private array $loaded = [];

    public function get(int $userId, string $field): ?string
    {
        if (! in_array($field, UserSensitiveProfile::FIELDS, true)) {
            return null;
        }

        if (! array_key_exists($userId, $this->loaded)) {
            $this->loaded[$userId] = UserSensitiveProfile::query()->where('user_id', $userId)->first();
        }

        return $this->loaded[$userId]?->{$field};
    }

    /**
     * Set the given fields (null clears one); others are left as they are.
     *
     * @param  array<string, ?string>  $values
     */
    public function update(User $user, array $values): void
    {
        $values = Arr::only($values, UserSensitiveProfile::FIELDS);

        if ($values === []) {
            return;
        }

        UserSensitiveProfile::query()->updateOrCreate(['user_id' => $user->id], $values);
        unset($this->loaded[$user->id]);

        activity()
            ->causedBy(auth()->user())
            ->performedOn($user)
            ->withProperties(['fields' => array_keys($values)])
            ->log('sensitive_profile_updated');
    }
}
