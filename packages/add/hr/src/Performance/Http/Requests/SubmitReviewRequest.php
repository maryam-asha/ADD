<?php

namespace Add\Hr\Performance\Http\Requests;

use Add\Hr\Performance\Enums\AnswerKind;
use Add\Hr\Performance\Enums\ReviewRecommendation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A self review or a manager review: a rating (and comment) per competency
 * or goal, an overall comment, and — the reviewer only — a recommendation.
 * ReviewService checks the items against the review and the ratings
 * against the cycle's scale.
 */
class SubmitReviewRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'answers' => ['present', 'array', 'max:100'],
            'answers.*.kind' => ['required', Rule::enum(AnswerKind::class)],
            'answers.*.key' => ['required', 'string', 'max:64'],
            'answers.*.rating' => ['required', 'integer'],
            'answers.*.comment' => ['nullable', 'string', 'max:2000'],
            'comment' => ['nullable', 'string', 'max:5000'],
            'recommendation' => ['nullable', Rule::enum(ReviewRecommendation::class)],
        ];
    }
}
