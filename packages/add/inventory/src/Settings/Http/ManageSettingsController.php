<?php

namespace Add\Inventory\Settings\Http;

use Add\Inventory\Settings\LockedSettingException;
use Add\Inventory\Settings\SettingsStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;

/**
 * The settings page: every setting with its value, the config file's
 * default and whether data already locks it; saving sends only the values
 * that changed, as `values: {key: value}`.
 */
class ManageSettingsController extends Controller
{
    public function index(SettingsStore $settings): JsonResponse
    {
        return response()->json(['data' => $settings->describe()]);
    }

    public function update(Request $request, SettingsStore $settings): JsonResponse
    {
        $values = $request->validate(['values' => ['required', 'array']])['values'];

        try {
            $settings->save($values);
        } catch (LockedSettingException $e) {
            throw ValidationException::withMessages(["values.{$e->key}" => [$e->getMessage()]]);
        }

        return response()->json(['message' => __('inventory::settings.saved')]);
    }
}
