<?php

namespace Add\Hr\Performance\Models;

use Add\Hr\Performance\Enums\AnswerKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The self and manager rating of one competency or goal in a review.
 */
class ReviewAnswer extends Model
{
    protected $table = 'hr_review_answers';

    protected $fillable = [
        'review_id',
        'kind',
        'item_key',
        'self_rating',
        'self_comment',
        'manager_rating',
        'manager_comment',
    ];

    protected $casts = [
        'kind' => AnswerKind::class,
        'self_rating' => 'integer',
        'manager_rating' => 'integer',
    ];

    public function review(): BelongsTo
    {
        return $this->belongsTo(Review::class);
    }
}
