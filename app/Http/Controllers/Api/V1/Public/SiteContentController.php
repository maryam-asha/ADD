<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Foundation\Models\Setting;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class SiteContentController extends Controller
{
    /**
     * Return front-door marketing site content stored in the settings table (S7-BE-01).
     * Enables content updates without redeploying the frontend.
     */
    public function show(): JsonResponse
    {
        $content = Setting::query()
            ->where(function ($query) {
                $query->where('key', 'like', 'site.%')
                    ->orWhere('key', 'like', 'public.%');
            })
            ->pluck('value', 'key');

        return response()->json([
            'data' => $content,
        ]);
    }
}
