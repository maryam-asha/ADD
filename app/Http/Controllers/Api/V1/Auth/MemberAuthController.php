<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RequestOtpRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\Otp\Exceptions\OtpThrottledException;
use App\Services\Otp\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MemberAuthController extends Controller
{
    public function __construct(private readonly OtpService $otp) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        try {
            $this->otp->request($request->validated('phone'));
        } catch (OtpThrottledException $e) {
            return response()->json([
                'message' => 'Too many requests. Please wait before requesting a new code.',
                'retry_after' => $e->retryAfterSeconds,
            ], 429);
        }

        return response()->json(['message' => 'Verification code sent.']);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $phone = $request->validated('phone');

        if (! $this->otp->verify($phone, $request->validated('code'))) {
            return response()->json(['message' => 'Invalid or expired code.'], 422);
        }

        $user = User::firstOrCreate(
            ['phone' => $phone],
            ['preferred_language' => 'ar', 'status' => 'active']
        );

        if ($user->wasRecentlyCreated) {
            $user->assignRole('member');
        }

        return response()->json([
            'token' => $user->createToken('member-app')->plainTextToken,
            'user' => new UserResource($user),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out.']);
    }
}
