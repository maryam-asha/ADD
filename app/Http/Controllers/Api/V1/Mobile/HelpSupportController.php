<?php

namespace App\Http\Controllers\Api\V1\Mobile;

use App\Domain\Ecosystem\Models\ContactLink;
use App\Domain\Ecosystem\Models\HelpSupport;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * The app's Help & Support page, readable before login — someone who cannot
 * sign in is exactly who needs it. `content` is Markdown in the request's
 * language (null until written); `contacts` are the visible contact links in
 * their order, each with the `url` tapping it opens, for the app to draw as
 * rows with an icon per `type`.
 */
class HelpSupportController extends Controller
{
    public function show(): JsonResponse
    {
        $contacts = ContactLink::query()
            ->where('is_visible', true)
            ->orderBy('sort_order')
            ->get()
            ->map(fn (ContactLink $link) => [
                'type' => $link->type,
                'label' => $link->label,
                'value' => $link->value,
                'url' => $link->url(),
            ])
            ->all();

        return response()->json(['data' => [
            'content' => HelpSupport::first()?->translate('content'),
            'contacts' => $contacts,
        ]]);
    }
}
