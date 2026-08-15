<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Settings\Enums\SettingScope;
use App\Domain\Settings\Models\Setting;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingRequest;
use App\Http\Resources\SettingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Deliberately not extending AdminResourceController: settings have no
 * 'order' column and no create/destroy — the key set is fixed by
 * SettingSeeder, not admin-authored — and "update" only ever changes
 * `value`. Same shape mismatch as UserController.
 */
class SettingController extends Controller
{
    use LogsSensitiveActions;

    public function index(): AnonymousResourceCollection
    {
        return SettingResource::collection(
            Setting::query()
                ->where('scope_type', SettingScope::Global)
                ->where('scope_id', 0)
                ->orderBy('key')
                ->get()
        );
    }

    public function update(UpdateSettingRequest $request, string $key, SettingService $settings): JsonResponse
    {
        $setting = $request->targetSetting();
        $before = $setting->resolvedValue();
        $after = $request->validated('value');

        $updated = $settings->set($key, $after, $setting->type);

        $this->logSensitiveAction('setting_updated', $setting, [
            'before' => $before,
            'after' => $updated->resolvedValue(),
        ]);

        return response()->json(['message' => __('api.admin.setting_updated')]);
    }
}
