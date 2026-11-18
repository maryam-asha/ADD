<?php

namespace App\Domain\Ecosystem\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ContactLink extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'value',
        'label',
        'sort_order',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'is_visible' => 'boolean',
        ];
    }

    /**
     * What tapping the row opens: a mail, a call, a WhatsApp chat, a map, or
     * the link itself. Null for a value with nothing to open.
     */
    public function url(): ?string
    {
        $value = trim((string) $this->value);
        $digits = preg_replace('/\D+/', '', $value);

        return match ($this->type) {
            'email' => "mailto:{$value}",
            'phone', 'mobile' => 'tel:'.(str_starts_with($value, '+') ? '+' : '').$digits,
            'whatsapp' => "https://wa.me/{$digits}",
            'telegram' => $this->isLink($value) ? $value : 'https://t.me/'.ltrim($value, '@'),
            'address' => $this->isLink($value) ? $value : 'https://www.google.com/maps/search/?api=1&query='.rawurlencode($value),
            default => $this->isLink($value) ? $value : null,
        };
    }

    private function isLink(string $value): bool
    {
        return str_starts_with($value, 'https://') || str_starts_with($value, 'http://');
    }
}
