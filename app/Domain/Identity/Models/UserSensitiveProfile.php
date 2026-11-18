<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A person's sensitive data, every value encrypted and hidden from
 * serialization. Read and written only by
 * App\Domain\Identity\Services\SensitiveProfileService — deliberately no
 * relation on User, so nothing that loads a user can carry it
 * (tests/Guards/SensitiveProfileStaysContainedTest).
 */
class UserSensitiveProfile extends Model
{
    /** Every sensitive field; a new one is a column, a cast and an entry here. */
    public const FIELDS = ['national_id', 'bank_account'];

    protected $fillable = [
        'user_id',
        'national_id',
        'bank_account',
    ];

    protected $hidden = self::FIELDS;

    protected function casts(): array
    {
        return [
            'national_id' => 'encrypted',
            'bank_account' => 'encrypted',
        ];
    }
}
