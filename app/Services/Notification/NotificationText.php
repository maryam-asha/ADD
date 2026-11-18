<?php

namespace App\Services\Notification;

use App\Domain\Settings\Services\SettingService;
use App\Http\Middleware\SetLocaleFromHeader;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Lang;

/**
 * A notification's title and body, written from its template
 * (`lang/{ar,en}/notifications.php`) for one locale. A row keeps the
 * template key and its params, so the inbox renders it in the language the
 * reader asks for and a push in each device's own language.
 *
 * A param may be translatable — `['ar' => '…', 'en' => '…']`, e.g. a
 * space's `name` column as it is — and resolves to the same locale.
 */
final class NotificationText
{
    /** Whether the template has a title and a body in every locale. */
    public static function exists(string $templateKey): bool
    {
        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            foreach (['title', 'body'] as $part) {
                if (! Lang::has("notifications.{$templateKey}.{$part}", $locale, false)) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{title: string, body: string}
     */
    public static function render(string $templateKey, array $params, string $locale): array
    {
        $params = self::resolveParams($params, $locale);

        return [
            'title' => __("notifications.{$templateKey}.title", $params, $locale),
            'body' => __("notifications.{$templateKey}.body", $params, $locale),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    public static function resolveParams(array $params, string $locale): array
    {
        return array_map(fn ($value) => is_array($value) ? self::pick($value, $locale) : (string) $value, $params);
    }

    /**
     * One lang line in every locale, as a translatable param — for a param
     * that is itself a lang line (a payment purpose).
     *
     * @param  array<string, mixed>  $replace
     * @return array<string, string>
     */
    public static function translations(string $langKey, array $replace = []): array
    {
        $lines = [];

        foreach (SetLocaleFromHeader::SUPPORTED_LOCALES as $locale) {
            $lines[$locale] = __($langKey, $replace, $locale);
        }

        return $lines;
    }

    /** A day as members read it: in the business's own timezone. */
    public static function localDate(CarbonInterface $at): string
    {
        return self::local($at)->format('Y-m-d');
    }

    /** A time of day as members read it: in the business's own timezone. */
    public static function localTime(CarbonInterface $at): string
    {
        return self::local($at)->format('H:i');
    }

    private static function local(CarbonInterface $at): CarbonInterface
    {
        return $at->copy()->setTimezone(app(SettingService::class)->get('app.timezone', 'Asia/Damascus'));
    }

    /**
     * The value for $locale out of a translatable one: that locale, else
     * English, else Arabic, else whatever it holds.
     *
     * @param  array<string, mixed>  $value
     */
    public static function pick(array $value, string $locale): string
    {
        return (string) ($value[$locale] ?? $value['en'] ?? $value['ar'] ?? reset($value) ?: '');
    }
}
