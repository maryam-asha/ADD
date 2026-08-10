<?php

namespace App\Domain\Identity\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * A validated sign-up waiting on its WhatsApp code. Holds the profile between
 * the two steps so `register/verify` needs nothing but the phone and the code —
 * which also means the password crosses the wire once instead of twice.
 *
 * This is not an account and must never be mistaken for one: it lives in its
 * own table precisely so no query over `users` has to know it exists.
 */
class PendingRegistration extends Model
{
    use MassPrunable;

    protected $fillable = [
        'phone',
        'name',
        'email',
        'password',
        'expires_at',
    ];

    /**
     * Hidden as a matter of habit rather than need — this model is never
     * serialized into a response — so that it stays true if one day it is.
     *
     * @var list<string>
     */
    protected $hidden = ['password'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Swept by `model:prune`, scheduled in routes/console.php. Abandoned
     * sign-ups are the normal case, not the exception — someone mistypes a
     * number, never receives a code, and walks away — so this table would grow
     * with unverified names and email addresses forever without it.
     */
    public function prunable(): Builder
    {
        return static::where('expires_at', '<', now());
    }

    public function isUsable(): bool
    {
        return $this->expires_at->isFuture();
    }
}
