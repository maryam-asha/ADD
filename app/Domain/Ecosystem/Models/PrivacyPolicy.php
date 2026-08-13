<?php

namespace App\Domain\Ecosystem\Models;

use App\Domain\Identity\Models\UserPrivacyPolicyConsent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PrivacyPolicy extends Model
{
    protected $fillable = ['content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }

    public function consents(): HasMany
    {
        return $this->hasMany(UserPrivacyPolicyConsent::class);
    }
}
