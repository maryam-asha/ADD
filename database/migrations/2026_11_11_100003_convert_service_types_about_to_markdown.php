<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A service's `about` page is Markdown from now on (it was HTML). Rewrites
 * the HTML already stored into Markdown: headings, paragraphs, bold, italic,
 * links, lists and line breaks; any other tag is dropped, its text kept. A
 * value with no tag in it is left as it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('service_types')->whereNotNull('about')->orderBy('id')->each(function (object $row) {
            $about = json_decode($row->about, true);

            if (! is_array($about)) {
                return;
            }

            $converted = array_map(fn ($text) => is_string($text) ? $this->toMarkdown($text) : $text, $about);

            if ($converted !== $about) {
                DB::table('service_types')->where('id', $row->id)->update([
                    'about' => json_encode($converted, JSON_UNESCAPED_UNICODE),
                ]);
            }
        });
    }

    public function down(): void
    {
        // Markdown is not turned back into HTML: the HTML it came from is gone.
    }

    private function toMarkdown(string $html): string
    {
        if (strip_tags($html) === $html) {
            return $html;
        }

        $md = preg_replace('/\r\n?/', "\n", $html);

        $md = preg_replace_callback('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', fn ($m) => "\n\n".str_repeat('#', (int) $m[1]).' '.trim($m[2])."\n\n", $md);
        $md = preg_replace('/<(strong|b)\b[^>]*>(.*?)<\/\1>/is', '**$2**', $md);
        $md = preg_replace('/<(em|i)\b[^>]*>(.*?)<\/\1>/is', '*$2*', $md);
        $md = preg_replace('/<a\b[^>]*href=["\']([^"\']*)["\'][^>]*>(.*?)<\/a>/is', '[$2]($1)', $md);
        $md = preg_replace('/<br\s*\/?>/i', "  \n", $md);
        $md = preg_replace_callback('/<ol\b[^>]*>(.*?)<\/ol>/is', function ($m) {
            $n = 0;

            return "\n\n".preg_replace_callback('/<li\b[^>]*>(.*?)<\/li>/is', function ($li) use (&$n) {
                return (++$n).'. '.trim($li[1])."\n";
            }, $m[1])."\n";
        }, $md);
        $md = preg_replace('/<li\b[^>]*>(.*?)<\/li>/is', "- $1\n", $md);
        $md = preg_replace('/<\/?(ul|p|div)\b[^>]*>/i', "\n\n", $md);

        $md = html_entity_decode(strip_tags($md), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $md = preg_replace("/\n{3,}/", "\n\n", $md);

        return trim($md);
    }
};
