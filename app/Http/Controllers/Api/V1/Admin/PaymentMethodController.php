<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Finance\Enums\PaymentPurpose;
use App\Domain\Finance\Enums\PaymentStatus;
use App\Domain\Finance\Models\Payment;
use App\Domain\Finance\Payments\PaymentMethodRegistry;
use App\Http\Controllers\Controller;
use App\Support\Time\LocalTime;
use Illuminate\Http\JsonResponse;

/**
 * The payment methods as operations sees them: every method in
 * config/payments.php, enabled or not, in the order members are offered
 * them — whether it is on, whether it is a card gateway, which purposes it
 * can pay for, and how it has been used.
 *
 * Read-only: a method is switched on or off in config (`enabled`), not here
 * (docs/decisions/unified-payments.md).
 */
class PaymentMethodController extends Controller
{
    public function index(PaymentMethodRegistry $registry, LocalTime $time): JsonResponse
    {
        $accepted = PaymentStatus::Accepted->value;
        $pending = [PaymentStatus::Pending->value, PaymentStatus::NeedsReview->value];

        $usage = Payment::query()
            ->selectRaw('method, COUNT(*) as payments_count, MAX(created_at) as last_used_at')
            ->selectRaw('SUM(CASE WHEN status = ? THEN 1 ELSE 0 END) as accepted_count', [$accepted])
            ->selectRaw('SUM(CASE WHEN status = ? THEN COALESCE(amount_points, points_to_credit, 0) ELSE 0 END) as accepted_points', [$accepted])
            ->selectRaw('SUM(CASE WHEN status IN (?, ?) THEN 1 ELSE 0 END) as open_count', $pending)
            ->groupBy('method')
            ->toBase()
            ->get()
            ->keyBy('method');

        $methods = collect($registry->all())->map(function (array $method, string $key) use ($usage, $time) {
            $driver = $method['driver'];
            $row = $usage->get($key);

            return [
                'key' => $key,
                'label' => $driver->label(),
                'enabled' => $method['enabled'],
                'gateway' => $method['gateway'],
                'purposes' => collect(PaymentPurpose::cases())
                    ->map(fn (PaymentPurpose $purpose) => ['purpose' => $purpose->value, 'supported' => $driver->supports($purpose)])
                    ->values()
                    ->all(),
                'usage' => [
                    'count' => (int) ($row->payments_count ?? 0),
                    'accepted_count' => (int) ($row->accepted_count ?? 0),
                    'accepted_points' => (int) ($row->accepted_points ?? 0),
                    // Waiting on money or on someone to look at it.
                    'open_count' => (int) ($row->open_count ?? 0),
                    'last_used_at' => $row?->last_used_at === null ? null : $time->toLocal($row->last_used_at)?->toIso8601String(),
                ],
            ];
        })->values();

        return response()->json(['data' => $methods]);
    }
}
