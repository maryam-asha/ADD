<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Concerns\LogsSensitiveActions;
use App\Domain\Pricing\Enums\CouponRevisionAction;
use App\Domain\Pricing\Enums\CouponSource;
use App\Domain\Pricing\Models\AppliedDiscount;
use App\Domain\Pricing\Models\Coupon;
use App\Domain\Pricing\Models\CouponRevision;
use App\Domain\Pricing\Services\CouponHistory;
use App\Http\Requests\Admin\StoreCouponRequest;
use App\Http\Requests\Admin\UpdateCouponRequest;
use App\Http\Resources\CouponRedemptionResource;
use App\Http\Resources\CouponResource;
use App\Http\Resources\CouponRevisionResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

/**
 * Discount coupons on the dashboard
 * (docs/superpowers/specs/2026-10-04-pricing-offers-coupons-design.md).
 * Extends AdminResourceController for the `coupons.*` permissions it
 * derives; the list is paginated and filtered rather than the generic
 * unpaginated one — coupons accumulate. Every write is audited: a code is
 * money off.
 */
class CouponController extends AdminResourceController
{
    use LogsSensitiveActions;

    protected function modelClass(): string
    {
        return Coupon::class;
    }

    protected function resourceClass(): string
    {
        return CouponResource::class;
    }

    protected function hasOrderColumn(): bool
    {
        return false;
    }

    /**
     * `search` matches the code; `is_active` the switch; `state` the window —
     * `running` (live now), `scheduled` (not started), `ended`.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $query = Coupon::query()
            ->with('targets')
            ->withCount(['redemptions as live_redemptions_count' => fn (Builder $q) => $q->live()])
            ->latest('id');

        if ($request->filled('search')) {
            $search = (string) $request->query('search');
            // A coupon by its code, an offer (which has none) by its name.
            $query->where(fn (Builder $q) => $q->where('code', 'like', '%'.Coupon::normalizeCode($search).'%')
                ->orWhere('name', 'like', '%'.$search.'%'));
        }

        // Offers (automatic) and coupons are managed on separate screens.
        if ($request->has('is_automatic')) {
            $query->where('is_automatic', $request->boolean('is_automatic'));
        }

        if ($request->has('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        }

        match ($request->query('state')) {
            'running' => $query->where(fn (Builder $q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', now()))
                ->where(fn (Builder $q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now())),
            'scheduled' => $query->where('starts_at', '>', now()),
            'ended' => $query->where('ends_at', '<=', now()),
            default => null,
        };

        return CouponResource::collection($query->paginate($request->integer('per_page', 15)));
    }

    public function show(int $id): JsonResource
    {
        return new CouponResource(
            Coupon::query()
                ->with(['targets', 'users'])
                ->withCount(['redemptions as live_redemptions_count' => fn (Builder $q) => $q->live()])
                ->findOrFail($id)
        );
    }

    public function store(StoreCouponRequest $request, CouponHistory $history): CouponResource
    {
        $validated = $request->validated();

        $coupon = DB::transaction(function () use ($validated, $request, $history) {
            $coupon = Coupon::create(array_merge(
                // One use per member unless the admin says otherwise (D11) —
                // a coupon's default; an offer is limited only when set.
                ['per_user_limit' => ($validated['is_automatic'] ?? false) ? null : 1],
                $this->couponAttributes($validated),
                ['source' => CouponSource::Admin, 'created_by' => $request->user()->id],
            ));

            $this->syncAudience($coupon, $validated);
            $history->record($coupon, CouponRevisionAction::Created, $request->user());

            return $coupon;
        });

        $this->logSensitiveAction('coupon_created', $coupon, $validated);

        return new CouponResource($coupon->refresh()->load(['targets', 'users']));
    }

    public function update(UpdateCouponRequest $request, Coupon $coupon, CouponHistory $history): JsonResponse
    {
        $validated = $request->validated();
        $before = $coupon->only(array_keys($this->couponAttributes($validated)));

        DB::transaction(function () use ($coupon, $validated, $history, $request) {
            $coupon->update($this->couponAttributes($validated));
            $this->syncAudience($coupon, $validated);
            $history->record($coupon, CouponRevisionAction::Updated, $request->user());
        });

        $this->logSensitiveAction('coupon_updated', $coupon, ['before' => $before, 'after' => $validated]);

        return response()->json(['message' => __('api.admin.coupon_updated')]);
    }

    /**
     * A coupon someone has used is deactivated, not deleted: its uses
     * keep the terms they were priced on, and point back to it.
     */
    public function destroy(int $id): Response
    {
        $coupon = Coupon::findOrFail($id);

        if ($coupon->redemptions()->exists()) {
            throw new HttpResponseException(response()->json(['message' => __('api.coupon.has_redemptions')], 409));
        }

        // Its history outlives it: a period it ran in still lists it.
        DB::transaction(function () use ($coupon) {
            app(CouponHistory::class)->record($coupon, CouponRevisionAction::Deleted, request()->user());
            $coupon->delete();
        });
        $this->logSensitiveAction('coupon_deleted', $coupon, ['code' => $coupon->code, 'name' => $coupon->name]);

        return response()->noContent();
    }

    /** Every version of it an admin saved, newest first (CouponHistory). */
    public function revisions(Request $request, Coupon $coupon): AnonymousResourceCollection
    {
        return CouponRevisionResource::collection(
            CouponRevision::query()->where('coupon_id', $coupon->id)->latest('recorded_at')->latest('id')->paginate($request->integer('per_page', 15))
        );
    }

    /** Who used it, newest first, and whether each use still counts. */
    public function redemptions(Request $request, Coupon $coupon): AnonymousResourceCollection
    {
        $page = $coupon->redemptions()->with('user')->latest('id')->paginate($request->integer('per_page', 15));
        $counting = AppliedDiscount::query()->whereKey($page->pluck('id'))->live()->pluck('id')->flip();

        $page->getCollection()->each(fn (AppliedDiscount $redemption) => $redemption->setAttribute('counts', $counting->has($redemption->id)));

        return CouponRedemptionResource::collection($page);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function couponAttributes(array $validated): array
    {
        return array_diff_key($validated, array_flip(['targets', 'user_ids']));
    }

    /**
     * Targets and members are replaced when sent and kept when left out.
     * Targets carry no history — a use keeps its own copy of the terms — so
     * replacing them is safe.
     *
     * @param  array<string, mixed>  $validated
     */
    private function syncAudience(Coupon $coupon, array $validated): void
    {
        if (array_key_exists('targets', $validated)) {
            $coupon->targets()->delete();

            foreach ($validated['targets'] as $target) {
                $coupon->targets()->create($target);
            }
        }

        if (array_key_exists('user_ids', $validated)) {
            $coupon->users()->sync($validated['user_ids']);
        }
    }
}
