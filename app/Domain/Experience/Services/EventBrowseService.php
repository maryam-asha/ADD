<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\Event;
use App\Domain\Identity\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class EventBrowseService
{
    /**
     * Resolve current locale from request header or app locale.
     */
    protected function resolveLocale(): string
    {
        $request = request();
        if ($request) {
            $raw = $request->header('lang') ?: $request->header('Accept-Language');
            if ($raw) {
                $header = strtolower(trim((string) $raw));
                if (str_contains($header, 'en')) {
                    return 'en';
                }
                if (str_contains($header, 'ar')) {
                    return 'ar';
                }
            }
        }

        return app()->getLocale() ?: 'ar';
    }

    /**
     * Browse events for the member app / user with filters:
     * - free: filter free / paid events
     * - cancelled: filter cancelled events
     * - search: search event title/name
     * - category: filter by category id or slug
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function browse(array $filters = [], ?User $user = null): array
    {
        $query = Event::query()
            ->with(['branch', 'category'])
            ->withCount('attendees');

        // 1. Cancelled / Status filter
        $isCancelled = null;
        if (array_key_exists('cancelled', $filters)) {
            $isCancelled = filter_var($filters['cancelled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (array_key_exists('is_cancelled', $filters)) {
            $isCancelled = filter_var($filters['is_cancelled'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($isCancelled === true) {
            $query->where('status', 'cancelled');
        } elseif ($isCancelled === false) {
            $query->where('status', 'published');
        } else {
            // Default for user: published events
            $query->where('status', 'published');
        }

        // 2. Search by event name/title in text
        if (! empty($filters['search'])) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('title->ar', 'like', $search)
                    ->orWhere('title->en', 'like', $search);
            });
        }

        // 3. Category filter (by id, slug, or generic category param)
        $categoryId = $filters['category_id'] ?? null;
        $categorySlug = $filters['category_slug'] ?? null;
        $category = $filters['category'] ?? null;

        if (! empty($categoryId)) {
            $query->where('category_id', (int) $categoryId);
        } elseif (! empty($categorySlug)) {
            $query->whereHas('category', function (Builder $q) use ($categorySlug) {
                $q->where('slug', $categorySlug);
            });
        } elseif (! empty($category)) {
            if (is_numeric($category)) {
                $query->where('category_id', (int) $category);
            } else {
                $query->whereHas('category', function (Builder $q) use ($category) {
                    $q->where('slug', $category);
                });
            }
        }

        // 4. Free / Paid filter
        $isFree = null;
        if (array_key_exists('free', $filters)) {
            $isFree = filter_var($filters['free'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        } elseif (array_key_exists('is_free', $filters)) {
            $isFree = filter_var($filters['is_free'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        }

        if ($isFree !== null) {
            $query->where('is_free', $isFree);
        }

        // Branch filter if provided
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        // Chronological ordering
        $query->orderBy('start_at', 'asc')->orderBy('order', 'asc');

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $paginator = $query->paginate($perPage);

        $events = collect($paginator->items())->map(fn (Event $event) => $this->formatEventCard($event, $user));

        return [
            'data' => $events,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    /**
     * Retrieve full event details for a single event.
     *
     * @return array<string, mixed>
     */
    public function details(Event|int|string $event, ?User $user = null): array
    {
        if (! $event instanceof Event) {
            $event = Event::with(['branch', 'category'])
                ->withCount('attendees')
                ->find($event);

            if (! $event) {
                throw new NotFoundHttpException(__('api.system.not_found'));
            }
        } else {
            $event->loadMissing(['branch', 'category']);
            if (! isset($event->attendees_count)) {
                $event->loadCount('attendees');
            }
        }

        return $this->formatEventDetails($event, $user);
    }

    /**
     * Retrieve active event categories for display in the app separately.
     * Returns id, name (translated by header language), icon, and color (no slug).
     *
     * @return array<int, array<string, mixed>>
     */
    public function categories(): array
    {
        $locale = $this->resolveLocale();

        return \App\Domain\Experience\Models\EventCategory::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (\App\Domain\Experience\Models\EventCategory $category) => [
                'id' => $category->id,
                'name' => $category->translate('name', $locale),
            ])
            ->values()
            ->all();
    }

    /**
     * Formats an event for the list cards with translated fields resolved by header language.
     *
     * @return array<string, mixed>
     */
    public function formatEventCard(Event $event, ?User $user = null): array
    {
        $locale = $this->resolveLocale();
        $start = $event->start_at ? Carbon::parse($event->start_at) : null;
        $end = $event->end_at ? Carbon::parse($event->end_at) : null;
        $capacity = $event->capacity !== null ? (int) $event->capacity : null;
        $attendeesCount = (int) ($event->attendees_count ?? 0);

        $seatsLeft = $capacity !== null ? max(0, $capacity - $attendeesCount) : null;
        $isFull = $capacity !== null && $seatsLeft === 0;
        $isCancelled = $event->status === 'cancelled';
        $isPast = $end ? $end->isPast() : false;

        $badge = $this->resolveBadge($event, $isCancelled, $isFull, $locale);
        $seatsLeftText = $this->resolveSeatsLeftText($capacity, $attendeesCount, $seatsLeft, $isCancelled, $isFull, $locale);

        $isRegistered = false;
        if ($user) {
            $isRegistered = $event->attendees()
                ->where(function ($q) use ($user) {
                    $q->where('email', $user->email);
                    if ($user->phone) {
                        $q->orWhere('phone', $user->phone);
                    }
                })
                ->exists();
        }

        $baseCurrency = $event->currency ?? 'USD';
        $targetCurrency = app(\App\Domain\Finance\Services\CurrencyResolver::class)->resolve(request() ?? new \Illuminate\Http\Request(), $user);
        $priceUsd = $event->price !== null ? (float) $event->price : 0.0;
        $priceDisplay = number_format($priceUsd, 2, '.', '');

        if (! $event->is_free && $priceUsd > 0 && $targetCurrency !== $baseCurrency) {
            $converted = app(\App\Domain\Finance\Services\CurrencyConversionService::class)->convert(
                $priceUsd,
                $baseCurrency,
                $targetCurrency
            );
            if ($converted !== null) {
                $priceDisplay = number_format($converted, 2, '.', '');
            }
        }

        return [
            'id' => $event->id,
            'title' => $event->translate('title', $locale),
            'category' => $event->category ? [
                'id' => $event->category->id,
                'name' => $event->category->translate('name', $locale),
            ] : null,
            'start_at' => $start?->format('Y-m-d'),
            'end_at' => $end?->format('Y-m-d'),
            'date' => [
                'month' => $start ? strtoupper($start->format('M')) : null,
                'day' => $start ? $start->format('d') : null,
                'weekday' => $start ? strtoupper($start->format('D')) : null,
                'formatted' => $start ? $start->format('F d, Y') : null,
            ],
            'time' => [
                'start' => $start ? $start->format('h:i A') : null,
                'end' => $end ? $end->format('h:i A') : null,
                'range' => $start && $end ? ($start->format('h:i A') . ' – ' . $end->format('h:i A')) : null,
            ],
            'location' => $event->location,
            'branch' => $event->branch ? [
                'id' => $event->branch->id,
                'name' => $event->branch->translate('name', $locale),
            ] : null,
            'image_url' => $event->image_url,
            'capacity' => $capacity,
            'attendees_count' => $attendeesCount,
            'seats_left' => $seatsLeft,
            'seats_left_text' => $seatsLeftText,
            'is_free' => (bool) ($event->is_free ?? true),
            'price' => $priceUsd,
            'currency' => $baseCurrency,
            'price_usd' => $priceUsd,
            'price_display' => $priceDisplay,
            'display_currency' => $targetCurrency,
            'status' => $event->status,
            'badge' => $badge,
            'is_registered' => $isRegistered,
            'can_register' => ! $isCancelled && ! $isFull && ! $isPast && $event->status === 'published',
        ];
    }

    /**
     * Formats an event for the full single details screen with translated fields resolved by header language.
     *
     * @return array<string, mixed>
     */
    public function formatEventDetails(Event $event, ?User $user = null): array
    {
        $card = $this->formatEventCard($event, $user);
        $locale = $this->resolveLocale();
        $start = $event->start_at ? Carbon::parse($event->start_at) : null;

        return array_merge($card, [
            'description' => $event->translate('description', $locale),
            'date_full' => $start ? $start->format('l, F j, Y') : null,
            'price_label' => $event->is_free
                ? ($locale === 'ar' ? 'مجاني' : 'Free')
                : (($event->currency ?? 'USD') . ' ' . (float) $event->price),
        ]);
    }

    /**
     * @return array{type: string, label: string}
     */
    private function resolveBadge(Event $event, bool $isCancelled, bool $isFull, string $locale = 'ar'): array
    {
        if ($isCancelled) {
            return [
                'type' => 'cancelled',
                'label' => $locale === 'ar' ? 'ملغى' : 'Cancelled',
            ];
        }

        if ($isFull) {
            return [
                'type' => 'full',
                'label' => $locale === 'ar' ? 'ممتلئ' : 'Full',
            ];
        }

        if (! $event->is_free && (float) $event->price > 0) {
            $symbol = $event->currency === 'USD' ? '$' : ($event->currency . ' ');
            return [
                'type' => 'paid',
                'label' => $symbol . (float) $event->price,
            ];
        }

        return [
            'type' => 'free',
            'label' => $locale === 'ar' ? 'مجاني' : 'Free',
        ];
    }

    private function resolveSeatsLeftText(?int $capacity, int $attendeesCount, ?int $seatsLeft, bool $isCancelled, bool $isFull, string $locale = 'ar'): string
    {
        if ($isCancelled) {
            return $locale === 'ar' ? 'ملغى' : 'Cancelled';
        }

        if ($capacity === null) {
            return $locale === 'ar' ? 'سعة مفتوحة' : 'Open capacity';
        }

        if ($isFull) {
            return $locale === 'ar'
                ? ('ممتلئ • ' . $capacity . '/' . $capacity . ' مقعد')
                : ('Full • ' . $capacity . '/' . $capacity . ' seats');
        }

        return $locale === 'ar'
            ? ($seatsLeft . ' / ' . $capacity . ' مقاعد متبقية')
            : ($seatsLeft . ' / ' . $capacity . ' seats left');
    }
}
