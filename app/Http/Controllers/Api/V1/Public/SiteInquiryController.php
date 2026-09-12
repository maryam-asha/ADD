<?php

namespace App\Http\Controllers\Api\V1\Public;

use App\Domain\Ecosystem\Models\SiteInquiry;
use App\Domain\Identity\Models\User;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\StoreSiteInquiryRequest;
use App\Services\Notification\NotificationService;
use Illuminate\Http\JsonResponse;

class SiteInquiryController extends Controller
{
    public function store(StoreSiteInquiryRequest $request, NotificationService $notificationService): JsonResponse
    {
        $inquiry = SiteInquiry::create($request->validated());

        // Notify operations and admin of the new inquiry (S7-BE-06)
        $recipients = User::role(['operations', 'admin'])->get();
        if ($recipients->isNotEmpty()) {
            $notificationService->sendToUsers(
                $recipients,
                'inquiry.new_received',
                [
                    'sender_name' => $inquiry->name,
                    'subject' => $inquiry->subject ?: 'General Inquiry',
                ],
                ['inquiry_id' => $inquiry->id]
            );
        }

        return response()->json([
            'message' => __('api.public.inquiry_submitted'),
        ], 201);
    }
}
