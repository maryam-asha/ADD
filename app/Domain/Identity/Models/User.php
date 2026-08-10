<?php

namespace App\Domain\Identity\Models;

use App\Domain\Foundation\Models\Branch;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\NewAccessToken;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasRoles, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'phone',
        'email',
        'password',
        'preferred_language',
        'preferred_currency',
        'status',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status_changed_at' => 'datetime',
            'anonymized_at' => 'datetime',
        ];
    }

    public function branchMemberships(): HasMany
    {
        return $this->hasMany(UserBranchMembership::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_memberships')
            ->withPivot('is_home_branch')
            ->withTimestamps();
    }

    public function notificationLogs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'company_user')
            ->using(CompanyUser::class)
            ->withPivot('door_access_enabled', 'is_admin')
            ->withTimestamps();
    }

    /**
     * Voluntary/administrative pause — the reversible half of the two
     * non-active states.
     */
    public function deactivate(?string $reason = null): void
    {
        $this->transitionStatusAndRevokeTokens('deactivated', $reason);
    }

    /**
     * Punitive/security block — the same immediate-token-revocation
     * treatment as deactivate(), distinguished only by `status`/`status_reason`
     * for reporting; nothing in the spend/access guard reads which one it is.
     */
    public function block(?string $reason = null): void
    {
        $this->transitionStatusAndRevokeTokens('blocked', $reason);
    }

    /**
     * Restores `active`. Doesn't revoke tokens — there's nothing to revoke:
     * deactivate()/block() already deleted every token this account had.
     */
    public function activate(?string $reason = null): void
    {
        DB::transaction(function () use ($reason) {
            $this->forceFill([
                'status' => 'active',
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => auth()->id(),
            ])->save();
        });
    }

    /**
     * Shared by deactivate()/block(): update the status/audit columns and
     * delete every access token this account holds, in one transaction — a
     * token that survives a status change is exactly the gap
     * EnsureUserIsActive's read-time check alone can't close (an already
     * per-request check still requires a request to happen; deleting the
     * token here means there is no valid token left to make one with).
     *
     * Refresh tokens are revoked in the same breath, and have to be: they are
     * spendable without an access token, so deleting only the access tokens
     * would leave a blocked account one /auth/refresh call away from a working
     * session again.
     */
    private function transitionStatusAndRevokeTokens(string $status, ?string $reason): void
    {
        DB::transaction(function () use ($status, $reason) {
            $this->forceFill([
                'status' => $status,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => auth()->id(),
            ])->save();

            $this->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $this->tokens()->delete();
        });
    }

    /**
     * Sanctum's default plain-text token is "{id}|{40 random chars}" — the id
     * prefix is just a lookup optimization. We skip it for a plain 64-char hex
     * token instead; Sanctum's own PersonalAccessToken::findToken() already
     * falls back to a hash lookup when there's no "|", so nothing else changes.
     */
    public function createToken(string $name, array $abilities = ['*'], ?\DateTimeInterface $expiresAt = null): NewAccessToken
    {
        $plainTextToken = bin2hex(random_bytes(32));

        $token = $this->tokens()->create([
            'name' => $name,
            'token' => hash('sha256', $plainTextToken),
            'abilities' => $abilities,
            'expires_at' => $expiresAt,
        ]);

        return new NewAccessToken($token, $plainTextToken);
    }
}
