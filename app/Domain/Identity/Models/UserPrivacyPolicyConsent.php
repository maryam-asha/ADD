<?php

namespace App\Domain\Identity\Models;

use App\Domain\Ecosystem\Models\PrivacyPolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserPrivacyPolicyConsent extends Model
{
    protected $fillable = ['user_id', 'privacy_policy_id', 'agreed_at'];

    protected function casts(): array
    {
        return ['agreed_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function privacyPolicy(): BelongsTo
    {
        return $this->belongsTo(PrivacyPolicy::class);
    }
}
