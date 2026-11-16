<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Ecosystem\Models\QuickAccessShortcut;
use App\Http\Requests\Admin\StoreQuickAccessShortcutRequest;
use App\Http\Requests\Admin\UpdateQuickAccessShortcutRequest;
use App\Http\Resources\QuickAccessShortcutResource;
use Illuminate\Http\JsonResponse;

/**
 * The member app home's quick-access tiles: listed in their `order`,
 * edited here, read by GET member/home (only the active ones).
 */
class QuickAccessShortcutController extends AdminResourceController
{
    protected function modelClass(): string
    {
        return QuickAccessShortcut::class;
    }

    protected function resourceClass(): string
    {
        return QuickAccessShortcutResource::class;
    }

    public function store(StoreQuickAccessShortcutRequest $request): QuickAccessShortcutResource
    {
        return new QuickAccessShortcutResource(QuickAccessShortcut::create(array_merge(
            ['order' => 0, 'is_active' => true],
            $request->validated()
        )));
    }

    public function update(UpdateQuickAccessShortcutRequest $request, QuickAccessShortcut $quickAccessShortcut): JsonResponse
    {
        $quickAccessShortcut->update($request->validated());

        return response()->json(['message' => __('api.admin.quick_access_shortcut_updated')]);
    }
}
