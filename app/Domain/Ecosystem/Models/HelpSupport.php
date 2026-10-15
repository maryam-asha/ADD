<?php

namespace App\Domain\Ecosystem\Models;

use App\Concerns\HasTranslations;
use Illuminate\Database\Eloquent\Model;

/**
 * The app's Help & Support text: a single row whose `content` is Markdown
 * in each language, edited from the dashboard. The page's contact rows are
 * the visible contact links, not part of this text.
 */
class HelpSupport extends Model
{
    use HasTranslations;

    protected $table = 'help_support';

    protected $fillable = ['content'];

    protected function casts(): array
    {
        return ['content' => 'array'];
    }
}
