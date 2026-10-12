<?php

namespace App\Http\Controllers\Api\V1\Admin\Reception;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Booking\Services\CoworkingSessionService;
use App\Domain\Booking\Services\SessionClosureService;
use App\Domain\Booking\Services\SessionReportBuilder;
use App\Domain\Finance\Exceptions\PaymentPricingException;
use App\Domain\Finance\Models\Currency;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Services\ReceivedMoneyConverter;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Services\DedicatedDeskService;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Settings\Services\SettingService;
use App\Http\Controllers\Controller;
use App\Http\Resources\ActiveReceptionSessionResource;
use App\Http\Resources\SessionEndRequestResource;
use App\Support\PlainAmount;
use App\Support\Time\LocalTime;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReceptionSessionsController extends Controller
{
    public function active(SessionClosureService $closures, SettingService $settings, DedicatedDeskService $desks): AnonymousResourceCollection
    {
        $timezone = $settings->get('app.timezone', 'Asia/Damascus');
        $now = Carbon::now($timezone);

        $bookings = Booking::where('status', BookingStatus::Confirmed)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'space.zone.floor', 'seatSpace.building', 'seatSpace.zone.floor', 'user.personalProfile', 'user.companies', 'pendingEndRequest')->get();

        $walkins = WalkinSession::whereNotNull('checked_in_at')->whereNull('checked_out_at')
            ->with('space.building.branch', 'space.zone.floor', 'user.personalProfile', 'user.companies', 'pendingEndRequest')->get();

        $bookings->each(function (Booking $booking) use ($closures, $now): void {
            $booking->type = 'booking';
            $booking->is_overdue = $this->isOverdue($closures, $booking, $now);
        });

        $walkins->each(function (WalkinSession $walkin) use ($closures, $now): void {
            $walkin->type = 'walkin';
            $walkin->is_overdue = $this->isOverdue($closures, $walkin, $now);
        });

        $sessions = $bookings->concat($walkins);

        // The desk a Dedicated Desk member sits at, so reception can send
        // them to it (docs/decisions/coworking-seat-pool.md).
        $codes = $desks->deskCodesByUser($sessions->pluck('user_id')->filter()->map(fn ($id) => (int) $id)->all());
        $sessions->each(fn ($session) => $session->desk_codes = $codes[$session->user_id] ?? []);

        return ActiveReceptionSessionResource::collection($sessions);
    }

    /**
     * The session's report, plus what the desk needs to end it: for a session
     * still open, what closing it would charge — at the moment the member
     * asked to end, else now — in points and in each currency cash is taken
     * in, and the member's points, so the desk knows whether their wallet
     * covers it.
     */
    public function report(string $type, int $id, CoworkingSessionService $sessions, SessionReportBuilder $reports, SessionClosureService $closures, WalletService $wallets, ReceivedMoneyConverter $converter): JsonResponse
    {
        $session = $sessions->find($type, $id);

        abort_if($session === null, 404);

        $closing = null;

        if ($session->checked_out_at === null) {
            $endRequest = $session->pendingEndRequest()->first();
            $at = $endRequest?->requested_at ?? now();
            $quote = $closures->quote($session, $at);

            $closing = [
                'at' => app(LocalTime::class)->toLocal($at)->toIso8601String(),
                'points' => $quote['points'],
                'covered_seconds' => $quote['covered_seconds'],
                'already_paid' => $quote['already_paid'],
                'amounts' => $this->amountsFor($quote['points'], $converter),
                'end_request' => $endRequest === null ? null : new SessionEndRequestResource($endRequest),
            ];
        }

        $personal = collect($wallets->spendOptions($session->user, WalletTransactionCategory::SpaceSpecific))
            ->firstWhere('owner_type', OwnerType::User->value);

        return response()->json(['data' => [
            ...$reports->build($session),
            'member' => [
                'id' => $session->user?->id,
                'name' => $session->user?->name,
                'phone' => $session->user?->phone,
            ],
            'closing' => $closing,
            'wallet_points' => $personal['usable_balance'] ?? 0,
            'payments' => $this->paymentsFor($session, $converter),
        ]]);
    }

    /**
     * How the session was paid, each payment as the desk explains it: the
     * method; the points it paid and what they are worth in each currency;
     * for cash, the money handed over, in what, and who took it; for a card,
     * what was charged, in what, and its reference. Oldest first.
     *
     * @return list<array<string, mixed>>
     */
    private function paymentsFor(Booking|WalkinSession $session, ReceivedMoneyConverter $converter): array
    {
        $time = app(LocalTime::class);

        return Payment::query()
            ->forPayable($session)
            ->with(['receivedBy:id,name', 'wallet'])
            ->orderBy('id')
            ->get()
            ->map(function (Payment $payment) use ($converter, $time) {
                $points = (int) ($payment->amount_points ?? $payment->points_to_credit ?? 0);

                return [
                    'id' => $payment->id,
                    'status' => $payment->status->value,
                    'method' => $payment->method,
                    'method_label' => __("api.payment.methods.{$payment->method}"),
                    'points' => $points,
                    'points_value' => $points > 0 ? $this->amountsFor($points, $converter) : [],
                    'wallet' => $payment->wallet === null ? null : [
                        'owner_type' => $payment->wallet->owner_type?->value ?? (string) $payment->wallet->owner_type,
                    ],
                    'cash' => $payment->received_amount === null ? null : [
                        'amount' => PlainAmount::of($payment->received_amount),
                        'currency' => $payment->received_currency,
                        'received_by' => $payment->receivedBy?->name,
                    ],
                    'card' => $payment->charged_amount === null ? null : [
                        'amount' => PlainAmount::of((string) $payment->charged_amount),
                        'currency' => $payment->currency,
                        'reference' => $payment->rrn,
                    ],
                    'at' => $time->toLocal($payment->received_at ?? $payment->created_at)?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * `$points` in each active currency; one with no exchange rate yet is
     * left out rather than failing the report.
     *
     * @return list<array{currency: string, value: string}>
     */
    private function amountsFor(int $points, ReceivedMoneyConverter $converter): array
    {
        return Currency::query()->where('is_active', true)->orderByDesc('is_base')->orderBy('order')->pluck('code')
            ->map(function (string $code) use ($converter, $points) {
                try {
                    return ['currency' => $code, 'value' => PlainAmount::of($converter->pointsToAmount($points, $code))];
                } catch (PaymentPricingException) {
                    return null;
                }
            })
            ->filter()
            ->values()
            ->all();
    }

    private function isOverdue(SessionClosureService $closures, Booking|WalkinSession $session, Carbon $now): bool
    {
        $branch = $session->space->building->branch;
        $closingTime = $closures->closingTimeFor($branch, $now);

        return $closingTime !== null && $now->format('H:i') > $closingTime;
    }
}
