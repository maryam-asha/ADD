<?php

namespace App\Domain\Experience\Services;

use App\Domain\Experience\Models\Event;
use App\Domain\Experience\Models\EventCategory;
use App\Domain\Identity\Models\User;
use App\Support\Enums\EventRegistrationStatus;
use App\Support\Presenters\EventPricePresenter;
use App\Support\Time\LocalTime;
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
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function browse(array $filters = [], ?User $user = null): array
    {
        $query = Event::query()
            ->with(['branch', 'category', 'space.building.branch'])
            ->withCount('seatHoldingAttendees as attendees_count');

        // 1. Cancelled / Status filter
        $this->applyStatusFilter($query, $filters);

        // 2. Search by event title/description in text (supports 'search' and 'q')
        $searchTerm = $filters['search'] ?? $filters['q'] ?? null;
        if (! empty($searchTerm)) {
            $search = '%'.trim((string) $searchTerm).'%';
            $query->where(function (Builder $q) use ($search) {
                $q->where('title->ar', 'like', $search)
                    ->orWhere('title->en', 'like', $search)
                    ->orWhere('description->ar', 'like', $search)
                    ->orWhere('description->en', 'like', $search);
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
        } elseif (! empty($filters['price_type'])) {
            $isFree = $filters['price_type'] === 'free';
        } elseif (! empty($filters['type'])) {
            $isFree = $filters['type'] === 'free';
        }

        if ($isFree !== null) {
            $query->where('is_free', $isFree);
        }

        // Branch filter if provided
        if (! empty($filters['branch_id'])) {
            $query->where('branch_id', (int) $filters['branch_id']);
        }

        // Soonest first, with anything already finished pushed below it.
        // A plain `order by start_at` would surface a month-old event above
        // one happening tomorrow, which is never what a calendar wants.
        // Within the past block the most recent comes first, so the list
        // reads outward from today in both directions.
        $query->orderByRaw('CASE WHEN COALESCE(end_at, start_at) >= ? THEN 0 ELSE 1 END asc', [Carbon::now()->toDateTimeString()])
            ->orderByRaw('CASE WHEN COALESCE(end_at, start_at) >= ? THEN start_at END asc', [Carbon::now()->toDateTimeString()])
            ->orderBy('start_at', 'desc')
            ->orderBy('order', 'asc');

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
                ->withCount('seatHoldingAttendees as attendees_count')
                ->find($event);

            if (! $event) {
                throw new NotFoundHttpException(__('api.system.not_found'));
            }
        } else {
            $event->loadMissing(['branch', 'category']);
            if (! isset($event->attendees_count)) {
                $event->loadCount('seatHoldingAttendees as attendees_count');
            }
        }

        return $this->formatEventDetails($event, $user);
    }

    /**
     * Retrieve events that the authenticated user is registered for ("My Events").
     * Supports tab filter: 'upcoming' (default) or 'past'.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function myEvents(User $user, array $filters = []): array
    {
        $now = Carbon::now();
        $nowStr = $now->toDateTimeString();

        // Base query condition for user's registration
        $userAttendeeCondition = function ($q) use ($user) {
            $q->where(function ($sub) use ($user) {
                $sub->where('user_id', $user->id)
                    ->orWhere('email', $user->email);
                if ($user->phone) {
                    $sub->orWhere('phone', $user->phone);
                }
            });
        };

        // Query for events registered by this user (exclude draft events)
        $baseQuery = Event::query()
            ->whereHas('attendees', $userAttendeeCondition)
            ->where('status', '!=', 'draft');

        // Calculate summary counts across all registered events for the user
        $upcomingCount = (clone $baseQuery)->where(function (Builder $q) use ($nowStr) {
            $q->where(function ($sub) use ($nowStr) {
                $sub->whereNotNull('end_at')->where('end_at', '>=', $nowStr);
            })->orWhere(function ($sub) use ($nowStr) {
                $sub->whereNull('end_at')->where('start_at', '>=', $nowStr);
            });
        })->count();

        $pastCount = (clone $baseQuery)->where(function (Builder $q) use ($nowStr) {
            $q->where(function ($sub) use ($nowStr) {
                $sub->whereNotNull('end_at')->where('end_at', '<', $nowStr);
            })->orWhere(function ($sub) use ($nowStr) {
                $sub->whereNull('end_at')->where('start_at', '<', $nowStr);
            });
        })->count();

        $totalCount = $upcomingCount + $pastCount;

        // Apply tab filter: 'upcoming' (default), 'past', or 'all'
        $tab = strtolower(trim((string) ($filters['tab'] ?? $filters['type'] ?? 'upcoming')));

        $query = (clone $baseQuery)
            ->with([
                'branch',
                'category',
                'space.building.branch',
                'attendees' => $userAttendeeCondition,
            ])
            ->withCount('seatHoldingAttendees as attendees_count');

        if ($tab === 'past') {
            $query->where(function (Builder $q) use ($nowStr) {
                $q->where(function ($sub) use ($nowStr) {
                    $sub->whereNotNull('end_at')->where('end_at', '<', $nowStr);
                })->orWhere(function ($sub) use ($nowStr) {
                    $sub->whereNull('end_at')->where('start_at', '<', $nowStr);
                });
            })->orderBy('start_at', 'desc');
        } elseif ($tab === 'all') {
            $query->orderBy('start_at', 'desc');
        } else {
            // Default: upcoming
            $tab = 'upcoming';
            $query->where(function (Builder $q) use ($nowStr) {
                $q->where(function ($sub) use ($nowStr) {
                    $sub->whereNotNull('end_at')->where('end_at', '>=', $nowStr);
                })->orWhere(function ($sub) use ($nowStr) {
                    $sub->whereNull('end_at')->where('start_at', '>=', $nowStr);
                });
            })->orderBy('start_at', 'asc');
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;
        $paginator = $query->paginate($perPage);

        $events = collect($paginator->items())->map(fn (Event $event) => $this->formatMyEventCard($event, $user));

        return [
            'counts' => [
                'upcoming' => $upcomingCount,
                'past' => $pastCount,
                'total' => $totalCount,
            ],
            'data' => $events,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ];
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

        return EventCategory::query()
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('id')
            ->get()
            ->map(fn (EventCategory $category) => [
                'id' => $category->id,
                'name' => $category->translate('name', $locale),
            ])
            ->values()
            ->all();
    }

    /**
     * Filter events by dynamic status (open, full, closed, cancelled, all).
     */
    /**
     * Correlated count of the seats an event has taken. Placeholders are
     * bound by the caller from EventRegistrationStatus, so the status list
     * here can never drift from the enum.
     */
    protected function seatCountSubquery(): string
    {
        $placeholders = implode(',', array_fill(
            0,
            count(EventRegistrationStatus::seatHoldingValues()),
            '?'
        ));

        return '(SELECT COUNT(*) FROM event_attendees'
            .' WHERE event_attendees.event_id = events.id'
            ." AND event_attendees.status IN ({$placeholders}))";
    }

    protected function applyStatusFilter(Builder $query, array $filters): void
    {
        $status = $filters['status'] ?? null;
        $cancelled = $filters['cancelled'] ?? $filters['is_cancelled'] ?? null;

        if ($cancelled !== null) {
            $isCancelled = filter_var($cancelled, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($isCancelled === true) {
                $query->where('status', 'cancelled');

                return;
            } elseif ($isCancelled === false) {
                $query->where('status', 'published');

                return;
            }
        }

        if (empty($status) || $status === 'all') {
            if ($status !== 'all') {
                $query->where('status', 'published');
            }

            return;
        }

        $statuses = is_array($status) ? $status : explode(',', (string) $status);
        $statuses = array_map('trim', array_map('strtolower', $statuses));

        $now = Carbon::now()->toDateTimeString();

        $query->where(function (Builder $q) use ($statuses, $now) {
            foreach ($statuses as $st) {
                $q->orWhere(function (Builder $sub) use ($st, $now) {
                    if ($st === 'cancelled') {
                        $sub->where('status', 'cancelled');
                    } elseif ($st === 'closed') {
                        $sub->where('status', 'published')
                            ->where(function (Builder $dateQ) use ($now) {
                                $dateQ->where(function ($d) use ($now) {
                                    $d->whereNotNull('end_at')->where('end_at', '<', $now);
                                })->orWhere(function ($d) use ($now) {
                                    $d->whereNull('end_at')->where('start_at', '<', $now);
                                });
                            });
                    } elseif ($st === 'full') {
                        $sub->where('status', 'published')
                            ->where(function (Builder $dateQ) use ($now) {
                                $dateQ->where(function ($d) use ($now) {
                                    $d->whereNotNull('end_at')->where('end_at', '>=', $now);
                                })->orWhere(function ($d) use ($now) {
                                    $d->whereNull('end_at')->where('start_at', '>=', $now);
                                });
                            })
                            ->whereNotNull('capacity')
                            ->whereRaw($this->seatCountSubquery().' >= events.capacity', EventRegistrationStatus::seatHoldingValues());
                    } elseif ($st === 'open') {
                        $sub->where('status', 'published')
                            ->where(function (Builder $dateQ) use ($now) {
                                $dateQ->where(function ($d) use ($now) {
                                    $d->whereNotNull('end_at')->where('end_at', '>=', $now);
                                })->orWhere(function ($d) use ($now) {
                                    $d->whereNull('end_at')->where('start_at', '>=', $now);
                                });
                            })
                            ->where(function (Builder $capQ) {
                                $capQ->whereNull('capacity')
                                    ->orWhereRaw($this->seatCountSubquery().' < events.capacity', EventRegistrationStatus::seatHoldingValues());
                            });
                    }
                });
            }
        });
    }

    /**
     * Retrieve all possible event statuses with localized titles.
     *
     * @return array<int, array<string, mixed>>
     */
    public function statuses(): array
    {
        $locale = $this->resolveLocale();

        return [
            [
                'id' => 'open',
                'slug' => 'open',
                'name' => $locale === 'en' ? 'Open for Registration' : 'متاح للتسجيل',
                'color' => '#10B981',
            ],
            [
                'id' => 'full',
                'slug' => 'full',
                'name' => $locale === 'en' ? 'Full' : 'مكتمل العدد',
                'color' => '#F59E0B',
            ],
            [
                'id' => 'closed',
                'slug' => 'closed',
                'name' => $locale === 'en' ? 'Closed / Past' : 'منتهي',
                'color' => '#6B7280',
            ],
            [
                'id' => 'cancelled',
                'slug' => 'cancelled',
                'name' => $locale === 'en' ? 'Cancelled' : 'ملغى',
                'color' => '#EF4444',
            ],
        ];
    }

    /**
     * Formats an event for the list cards with translated fields resolved by header language.
     *
     * @return array<string, mixed>
     */
    public function formatEventCard(Event $event, ?User $user = null): array
    {
        $locale = $this->resolveLocale();
        $start = app(LocalTime::class)->toLocal($event->start_at);
        $end = app(LocalTime::class)->toLocal($event->end_at);
        $capacity = $event->capacity !== null ? (int) $event->capacity : null;
        $attendeesCount = (int) ($event->attendees_count ?? 0);

        $seatsLeft = $capacity !== null ? max(0, $capacity - $attendeesCount) : null;
        $isFull = $capacity !== null && $seatsLeft === 0;
        $isCancelled = $event->status === 'cancelled';
        $now = Carbon::now();
        $isPast = $end ? $end->isBefore($now) : ($start ? $start->isBefore($now) : false);

        $computedStatus = 'open';
        if ($isCancelled) {
            $computedStatus = 'cancelled';
        } elseif ($isPast) {
            $computedStatus = 'closed';
        } elseif ($isFull) {
            $computedStatus = 'full';
        }

        $badge = $this->resolveBadge($event, $isCancelled, $isFull, $locale);
        $seatsLeftText = $this->resolveSeatsLeftText($capacity, $attendeesCount, $seatsLeft, $isCancelled, $isFull, $locale);

        // One lookup, not two: the row itself answers both "is this member
        // in" and "where does their registration stand". Rejected rows are
        // excluded, so a rejected member may register again.
        $myRegistration = $user
            ? $event->attendees()
                ->seatHolding()
                ->where(function ($q) use ($user) {
                    $q->where('user_id', $user->id)
                        ->orWhere('email', $user->email);
                    if ($user->phone) {
                        $q->orWhere('phone', $user->phone);
                    }
                })
                ->first()
            : null;

        $isRegistered = $myRegistration !== null;
        $registrationStatus = $myRegistration?->status?->value;

        // Member-facing: the two display labels only. The raw point count
        // and the unformatted currency figures stay server-side —
        // registration debits the wallet and checks the balance there, so
        // a client has nothing to compute.
        $price = app(EventPricePresenter::class)->presentForDisplay($event, $user, $locale);

        return [
            'id' => $event->id,
            'title' => $event->translate('title', $locale),
            'description' => $event->translate('description', $locale),
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
                'range' => $start && $end ? ($start->format('h:i A').' – '.$end->format('h:i A')) : null,
            ],
            'location' => app(EventLocationService::class)->displayLocation($event, $locale),
            'space_id' => $event->space_id,
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
            ...$price,
            'status' => $event->status,
            'computed_status' => $computedStatus,
            'badge' => $badge,
            'is_registered' => $isRegistered,
            'registration_status' => $registrationStatus,
            'requires_approval' => (bool) $event->requires_approval,
            'has_registration_form' => (bool) $event->has_registration_form,
            'external_form_url' => $event->external_form_url,
            // A member already holding a seat cannot take a second one —
            // this used to say true and then 422 on the attempt. A
            // rejected registration is not seat-holding, so they may
            // register again.
            'can_register' => ! $event->registersExternally()
                && ! $isCancelled && ! $isFull && ! $isPast
                && $event->status === 'published'
                && ! $isRegistered,
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
        $start = app(LocalTime::class)->toLocal($event->start_at);

        // price_label already comes from formatEventCard() via the
        // presenter — no second copy of the formatting here.
        return array_merge($card, [
            'description' => $event->translate('description', $locale),
            'date_full' => $start ? $start->format('l, F j, Y') : null,
        ]);
    }

    /**
     * Formats an event card specifically for the "My Events" screen.
     * Matches the mobile design with dual badges, formatted date, ticket id, and action buttons.
     *
     * @return array<string, mixed>
     */
    public function formatMyEventCard(Event $event, User $user): array
    {
        $locale = $this->resolveLocale();
        $card = $this->formatEventCard($event, $user);

        $attendee = $event->attendees ? $event->attendees->first() : null;
        $now = Carbon::now();
        $start = app(LocalTime::class)->toLocal($event->start_at);
        $end = app(LocalTime::class)->toLocal($event->end_at);
        $isPast = $end ? $end->isBefore($now) : ($start ? $start->isBefore($now) : false);

        // Where the registration stands. A decision outranks attendance:
        // only an approved registration can go on to be attended or missed.
        $isApproved = $attendee?->status === EventRegistrationStatus::Approved;

        if ($attendee?->status === EventRegistrationStatus::Pending) {
            $regStatus = 'pending';
            $regLabel = $locale === 'ar' ? 'قيد المراجعة' : 'Pending review';
        } elseif ($attendee?->status === EventRegistrationStatus::Rejected) {
            $regStatus = 'rejected';
            $regLabel = $locale === 'ar' ? 'مرفوض' : 'Rejected';
        } else {
            $regStatus = 'registered';
            $regLabel = $locale === 'ar' ? 'مسجّل' : 'Registered';

            if ($isApproved && $attendee?->attended === true) {
                $regStatus = 'attended';
                $regLabel = $locale === 'ar' ? 'حضر' : 'Attended';
            } elseif ($isApproved && $isPast && $attendee?->attended === false) {
                $regStatus = 'missed';
                $regLabel = $locale === 'ar' ? 'لم يحضر' : 'Missed';
            }
        }

        // Time status badge
        $timeStatusType = $isPast ? 'past' : 'upcoming';
        $timeStatusLabel = $isPast
            ? ($locale === 'ar' ? 'سابق' : 'Past')
            : ($locale === 'ar' ? 'قادم' : 'Upcoming');

        // Formatted date (e.g. "Thu, Nov 21, 2026 · 6:00 PM")
        $dateFormatted = null;
        if ($start) {
            $hasTime = $start->format('H:i') !== '00:00';
            $dateFormatted = $start->format('D, M d, Y').($hasTime ? ' · '.$start->format('h:i A') : '');
        }

        // Badges list for UI
        $myBadges = [
            [
                'type' => $regStatus,
                'label' => $regLabel,
                'category' => 'registration',
            ],
            [
                'type' => $timeStatusType,
                'label' => $timeStatusLabel,
                'category' => 'time',
            ],
        ];

        // Action button
        $ticketId = $attendee?->id;
        $action = (! $isPast && $ticketId && $isApproved)
            ? [
                'type' => 'view_ticket',
                'label' => $locale === 'ar' ? 'عرض التذكرة' : 'View Ticket',
                'ticket_id' => $ticketId,
            ]
            : [
                'type' => 'view_event',
                'label' => $locale === 'ar' ? 'عرض الفعالية' : 'View Event',
                'event_id' => $event->id,
            ];

        return array_merge($card, [
            'ticket_id' => $ticketId,
            'date_formatted' => $dateFormatted,
            'registration' => [
                'status' => $regStatus,
                'label' => $regLabel,
                'attended' => $attendee?->attended,
                'rejection_reason' => $attendee?->rejection_reason,
                'registered_at' => $attendee?->created_at?->toISOString(),
            ],
            'time_status' => [
                'type' => $timeStatusType,
                'label' => $timeStatusLabel,
            ],
            'my_badges' => $myBadges,
            'action' => $action,
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

        if (! $event->is_free && (int) ($event->price_points ?? 0) > 0) {
            return [
                'type' => 'paid',
                'label' => __('api.event.price_points', ['points' => (int) $event->price_points], $locale),
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
                ? ('ممتلئ • '.$capacity.'/'.$capacity.' مقعد')
                : ('Full • '.$capacity.'/'.$capacity.' seats');
        }

        return $locale === 'ar'
            ? ($seatsLeft.' / '.$capacity.' مقاعد متبقية')
            : ($seatsLeft.' / '.$capacity.' seats left');
    }
}
