<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Domain\Booking\Enums\BookingStatus;
use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Finance\Payments\OutstandingItems;
use App\Domain\Finance\Services\CurrencyConversionService;
use App\Domain\Identity\Models\User;
use App\Domain\Membership\Enums\OwnerType;
use App\Domain\Membership\Enums\WalletTransactionCategory;
use App\Domain\Membership\Models\Membership;
use App\Domain\Membership\Models\Wallet;
use App\Domain\Membership\Models\WalletTransaction;
use App\Domain\Membership\Services\MembershipResolver;
use App\Domain\Membership\Services\PlanHourCoverageService;
use App\Domain\Membership\Services\ResourceQuotaService;
use App\Domain\Membership\Services\WalletHistoryService;
use App\Domain\Membership\Services\WalletService;
use App\Domain\Services\Models\ServiceRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\MemberProfile\ListMemberEventRegistrationsRequest;
use App\Http\Requests\Admin\MemberProfile\ListMemberMembershipsRequest;
use App\Http\Requests\Admin\MemberProfile\ListMemberServiceRequestsRequest;
use App\Http\Requests\Admin\MemberProfile\ListMemberSessionsRequest;
use App\Http\Requests\Member\ListWalletTransactionsRequest;
use App\Http\Resources\Services\ServiceRequestResource;
use App\Http\Resources\UserResource;
use App\Support\Presenters\WalletTransactionPresenter;
use App\Support\Time\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * One member's whole file, for the dashboard's member profile: a summary
 * for its header, then each of its tabs — sessions, wallet history,
 * memberships, service requests and event registrations. Bookings and
 * payments need no endpoint of their own: `GET bookings?user_id=` and
 * `GET payments?user_id=` already filter by member.
 *
 * Read-only, and for operations as well as admins: the desk looks a member
 * up as often as anyone.
 */
class MemberProfileController extends Controller
{
    public function __construct(private readonly LocalTime $time) {}

    /**
     * The header: who they are, what they hold (points, the plan they are on
     * and its hours left), what they owe, whether they are inside now, and a
     * few counts.
     */
    public function summary(
        User $user,
        WalletService $wallets,
        OutstandingItems $outstanding,
        MembershipResolver $memberships,
        PlanHourCoverageService $planHours,
        ResourceQuotaService $quotas,
        CurrencyConversionService $currencies,
    ): JsonResponse {
        $user->loadMissing(['roles', 'companies', 'personalProfile', 'professionalProfile', 'branches', 'deviceTokens', 'privacyPolicyConsents']);

        $wallet = $this->personalWallet($user);
        $balances = $wallet === null
            ? array_fill_keys(array_map(fn (WalletTransactionCategory $c) => $c->value, WalletTransactionCategory::cases()), 0)
            : $wallets->balances($wallet, $user);

        $owed = $outstanding->for($user->id, $currencies->baseCurrencyCode());
        $membership = $memberships->activeFor($user);
        $grant = $planHours->hourGrantFor($membership);

        $openBooking = Booking::query()->with('space')->where('user_id', $user->id)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')->latest('checked_in_at')->first();
        $openWalkIn = WalkinSession::query()->with('space')->where('user_id', $user->id)
            ->whereNotNull('checked_in_at')->whereNull('checked_out_at')->latest('checked_in_at')->first();
        $open = $openWalkIn ?? $openBooking;

        $lastVisit = collect([
            Booking::query()->where('user_id', $user->id)->max('checked_in_at'),
            WalkinSession::query()->where('user_id', $user->id)->max('checked_in_at'),
        ])->filter()->map(fn ($at) => CarbonImmutable::parse($at, 'UTC'))->max();

        return response()->json(['data' => [
            'member' => new UserResource($user),
            'joined_at' => $this->local($user->created_at),
            'profile' => $this->profile($user),
            'last_visit_at' => $this->local($lastVisit),
            'wallet' => [
                'points' => $balances[WalletTransactionCategory::General->value],
                'categories' => collect($balances)->map(fn (int $points, string $category) => [
                    'category' => $category,
                    'label' => __("api.wallet.categories.{$category}"),
                    'points' => $points,
                ])->values(),
            ],
            'outstanding' => [
                'points' => $owed['total_points'],
                'amount' => $owed['total_amount'],
                'items' => count($owed['items']),
            ],
            'membership' => $membership === null ? null : [
                'id' => $membership->id,
                'plan' => $membership->plan?->translate('name'),
                'period_end' => $this->local($membership->current_period_end),
                'hours_remaining' => $grant === null ? null : $quotas->remainingFor($membership, $grant->resourceType),
            ],
            'open_session' => $open === null ? null : [
                'type' => $open->coworkingType(),
                'id' => $open->id,
                'space' => $open->space?->displayTitle(app()->getLocale()),
                'checked_in_at' => $this->local($open->checked_in_at),
            ],
            'counts' => [
                'bookings' => Booking::query()->where('user_id', $user->id)->count(),
                // A booking held for its payment is not upcoming until it is paid or switched to another method.
                'upcoming_bookings' => Booking::query()->where('user_id', $user->id)
                    ->whereIn('status', BookingStatus::expected())->where('start_at', '>', now())->count(),
                'sessions' => WalkinSession::query()->where('user_id', $user->id)->count()
                    + Booking::query()->where('user_id', $user->id)->whereNotNull('checked_in_at')->count(),
                'points_spent' => $wallet === null ? 0 : (int) abs((int) WalletTransaction::query()
                    ->where('wallet_id', $wallet->id)->where('amount', '<', 0)->sum('amount')),
                'service_requests' => ServiceRequest::query()->where('user_id', $user->id)->count(),
                'event_registrations' => EventAttendee::query()->where('user_id', $user->id)->count(),
            ],
        ]]);
    }

    /**
     * Every time they were inside, newest first: walk-ins and the bookings
     * they checked into, one list.
     */
    public function sessions(ListMemberSessionsRequest $request, User $user): JsonResponse
    {
        $filters = $request->validated();
        $type = $filters['type'] ?? null;

        $narrow = function ($query) use ($filters) {
            return $query
                ->whereNotNull('checked_in_at')
                ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('checked_in_at', '>=', $this->startOfLocalDay($from)))
                ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('checked_in_at', '<', $this->startOfLocalDay($to)->addDay()))
                ->when($filters['payment_state'] ?? null, fn ($q, $state) => $q->where('payment_state', $state))
                ->when(isset($filters['open']), fn ($q) => $filters['open'] ? $q->whereNull('checked_out_at') : $q->whereNotNull('checked_out_at'));
        };

        $rows = collect()
            ->concat($type === 'booking' ? [] : $narrow(WalkinSession::query()->with('space')->where('user_id', $user->id))->get())
            ->concat($type === 'walkin' ? [] : $narrow(Booking::query()->with('space')->where('user_id', $user->id))->get())
            ->sortByDesc(fn ($session) => $session->checked_in_at->getTimestamp())
            ->values();

        return $this->page($this->paginate($rows, (int) ($filters['per_page'] ?? 20), $request->integer('page', 1)), fn ($session) => [
            'type' => $session->coworkingType(),
            'id' => $session->id,
            'reference' => $session instanceof Booking ? $session->reference : null,
            'space' => ['id' => $session->space?->id, 'name' => $session->space?->displayTitle(app()->getLocale())],
            'checked_in_at' => $this->local($session->checked_in_at),
            'checked_out_at' => $this->local($session->checked_out_at),
            'duration_seconds' => (int) $session->checked_in_at->diffInSeconds($session->checked_out_at ?? now()),
            'plan_covered_seconds' => $session instanceof WalkinSession ? (int) $session->plan_covered_seconds : 0,
            'price_points' => $session->price_points === null ? null : (int) $session->price_points,
            'payment_state' => $session->payment_state?->value,
            'payment_method' => $session->payment_method?->value,
            'termination_source' => $session->termination_source?->value,
        ]);
    }

    /** Their personal wallet's rows, newest first, as the app shows them. */
    public function walletTransactions(ListWalletTransactionsRequest $request, User $user, WalletHistoryService $history, WalletTransactionPresenter $presenter): JsonResponse
    {
        $paginator = $history->paginate($user, $request->safe()->only(['type', 'category', 'from', 'to']), (int) ($request->validated('per_page') ?? 20));

        return $this->page($paginator, fn (WalletTransaction $transaction) => $presenter->item($transaction, $user));
    }

    /** Their own subscriptions and their companies', newest first. */
    public function memberships(ListMemberMembershipsRequest $request, User $user, MembershipResolver $memberships): JsonResponse
    {
        $paginator = $memberships->heldBy($user)
            ->with(['plan', 'seatDesks'])
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('current_period_start')
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 20));

        $companies = $user->companies->keyBy('id');

        return $this->page($paginator, fn (Membership $membership) => [
            'id' => $membership->id,
            'plan' => ['id' => $membership->plan_id, 'name' => $membership->plan?->translate('name')],
            'via' => $membership->owner_type === OwnerType::Company ? 'company' : 'personal',
            'company' => $membership->owner_type === OwnerType::Company
                ? ['id' => $membership->owner_id, 'name' => $companies->get($membership->owner_id)?->legal_name]
                : null,
            'status' => $membership->status?->value ?? $membership->status,
            'seats' => $membership->seats,
            'desks' => $membership->seatDesks->map(fn ($desk) => ['code' => $desk->code, 'label' => $desk->label])->values(),
            'current_period_start' => $this->local($membership->current_period_start),
            'current_period_end' => $this->local($membership->current_period_end),
        ]);
    }

    /** What they asked the operations team for, newest first. */
    public function serviceRequests(ListMemberServiceRequestsRequest $request, User $user): JsonResponse
    {
        $filters = $request->validated();

        $paginator = ServiceRequest::query()
            ->with(['serviceType', 'branch', 'space'])
            ->where('user_id', $user->id)
            ->when($filters['status'] ?? null, fn ($q, $status) => $q->where('status', $status))
            ->when($filters['from'] ?? null, fn ($q, $from) => $q->where('submitted_at', '>=', $this->startOfLocalDay($from)))
            ->when($filters['to'] ?? null, fn ($q, $to) => $q->where('submitted_at', '<', $this->startOfLocalDay($to)->addDay()))
            ->orderByDesc('submitted_at')
            ->paginate((int) ($filters['per_page'] ?? 20));

        return $this->page($paginator, fn (ServiceRequest $serviceRequest) => (new ServiceRequestResource($serviceRequest))->resolve());
    }

    /** The events they registered for, with whether they came and paid. */
    public function eventRegistrations(ListMemberEventRegistrationsRequest $request, User $user): JsonResponse
    {
        $paginator = EventAttendee::query()
            ->with('event')
            ->where('user_id', $user->id)
            ->when($request->validated('status'), fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate((int) ($request->validated('per_page') ?? 20));

        return $this->page($paginator, fn (EventAttendee $attendee) => [
            'id' => $attendee->id,
            'event' => [
                'id' => $attendee->event?->id,
                'title' => $attendee->event?->translate('title'),
                'start_at' => $this->local($attendee->event?->start_at),
            ],
            'status' => $attendee->status instanceof \BackedEnum ? $attendee->status->value : $attendee->status,
            'attended' => (bool) $attendee->attended,
            'price_points' => (int) $attendee->price_points,
            'payment_state' => $attendee->payment_state?->value,
            'registered_at' => $this->local($attendee->created_at),
            'checked_in_at' => $this->local($attendee->checked_in_at),
        ]);
    }

    /**
     * Everything the member told us and everything their account holds:
     * contact and preferences, their personal and professional profiles and
     * links, their companies with what each lets them do, their branches,
     * when they accepted the privacy policy, and the devices they use.
     *
     * @return array<string, mixed>
     */
    private function profile(User $user): array
    {
        $personal = $user->personalProfile;
        $professional = $user->professionalProfile;
        $consent = $user->privacyPolicyConsents->sortByDesc('agreed_at')->first();

        return [
            'account' => [
                'phone' => $user->phone,
                'email' => $user->email,
                'preferred_language' => $user->preferred_language,
                'preferred_currency' => $user->preferred_currency,
                'status' => $user->status,
                'roles' => $user->getRoleNames()->values(),
                'joined_at' => $this->local($user->created_at),
                'updated_at' => $this->local($user->updated_at),
            ],
            'personal' => [
                'bio' => $personal?->bio,
                'city' => $personal?->city,
                'gender' => $personal?->gender?->value,
                'avatar_url' => $personal?->avatar_url,
            ],
            'professional' => [
                'job_title' => $professional?->job_title,
                'company_name' => $professional?->company_name,
                'industry' => $professional?->industry,
            ],
            'links' => [
                'linkedin_url' => $professional?->linkedin_url,
                'instagram_url' => $professional?->instagram_url,
                'behance_url' => $professional?->behance_url,
                'website_url' => $professional?->website_url,
            ],
            'companies' => $user->companies->map(fn ($company) => [
                'id' => $company->id,
                'legal_name' => $company->legal_name,
                'status' => $company->status?->value ?? (string) $company->status,
                'is_admin' => (bool) $company->pivot?->is_admin,
                'door_access_enabled' => (bool) $company->pivot?->door_access_enabled,
                'joined_at' => $this->local($company->pivot?->created_at),
            ])->values(),
            'branches' => $user->branches->map(fn ($branch) => [
                'id' => $branch->id,
                'name' => $branch->translate('name'),
                'is_home_branch' => (bool) $branch->pivot?->is_home_branch,
            ])->values(),
            'privacy_policy_agreed_at' => $this->local($consent?->agreed_at),
            'devices' => $user->deviceTokens->sortByDesc('last_used_at')->map(fn ($device) => [
                'platform' => $device->platform,
                'last_used_at' => $this->local($device->last_used_at),
            ])->values(),
        ];
    }

    private function personalWallet(User $user): ?Wallet
    {
        return Wallet::query()->where('owner_type', OwnerType::User)->where('owner_id', $user->id)->first();
    }

    /** `{data, meta}`, the shape every paginated admin list here returns. */
    private function page(Paginator $paginator, callable $row): JsonResponse
    {
        return response()->json([
            'data' => collect($paginator->items())->map($row)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    private function paginate(Collection $rows, int $perPage, int $page): LengthAwarePaginator
    {
        $page = max(1, $page);

        return new LengthAwarePaginator($rows->forPage($page, $perPage)->values()->all(), $rows->count(), $perPage, $page);
    }

    private function local(mixed $instant): ?string
    {
        return $instant === null ? null : $this->time->toLocal($instant)->toIso8601String();
    }

    /** A district calendar day (Y-m-d) as the UTC instant it starts at. */
    private function startOfLocalDay(string $day): CarbonImmutable
    {
        return CarbonImmutable::parse($this->time->toUtc($day.' 00:00'));
    }
}
