<?php

namespace App\Domain\Identity\Models;

use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * PRD §5.11. Polymorphic on `subject_type`/`subject_id` rather than an
 * Eloquent morph relation: the two possible subject tables (`users`,
 * `community_members`) don't share a common ancestor, and only the `user`
 * side has a write path today (Phase 9 wires `community_member`).
 */
class Consent extends Model
{
    use HasFactory;

    protected $fillable = [
        'subject_type',
        'subject_id',
        'consent_type',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'subject_type' => ConsentSubjectType::class,
            'consent_type' => ConsentType::class,
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at');
    }

    public static function grant(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): self
    {
        return static::create([
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'consent_type' => $consentType,
            'granted_at' => now(),
        ]);
    }

    public static function revokeActive(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): void
    {
        static::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('consent_type', $consentType->value)
            ->active()
            ->update(['revoked_at' => now()]);
    }

    public static function hasActive(ConsentSubjectType $subjectType, int $subjectId, ConsentType $consentType): bool
    {
        return static::query()
            ->where('subject_type', $subjectType->value)
            ->where('subject_id', $subjectId)
            ->where('consent_type', $consentType->value)
            ->active()
            ->exists();
    }
}
