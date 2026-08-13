<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountController extends Controller
{
    public function delete(): JsonResponse
    {
        $user = auth()->user();
        $user->delete();

        return response()->json(['message' => __('api.auth.account_deleted')]);
    }

    public function updateProfileImage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'avatar_url' => 'required|image|max:5120',
        ]);

        $file = $request->file('avatar_url');
        $path = $file->store('users/'.auth()->id(), 'public');

        auth()->user()->personalProfile()->updateOrCreate(
            ['user_id' => auth()->id()],
            ['avatar_url' => $path]
        );

        return response()->json(['message' => __('api.profile.image_updated')]);
    }
}
