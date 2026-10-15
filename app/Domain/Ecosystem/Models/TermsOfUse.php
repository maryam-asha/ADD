<?php

namespace App\Domain\Ecosystem\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * The app's terms of use: a single row whose `content` is Markdown in each
 * language, edited from the dashboard like the privacy policy.
 */
class TermsOfUse extends Model
{
    use HasTranslations;

    protected $table = 'terms_of_use';

    protected $fillable = ['content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
