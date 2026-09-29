<?php

namespace App\Providers;

use App\Domain\Booking\Models\Booking;
use App\Domain\Booking\Models\WalkinSession;
use App\Domain\Ecosystem\Models\EventAttendee;
use App\Domain\Experience\Models\Event;
use App\Domain\Services\Models\ServiceRequest;
use App\Domain\Services\Models\ServiceType;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

/**
 * Stable aliases for every model stored in a polymorphic column by the
 * form engine — form owners (`forms.formable_type`) and the records that
 * hold answers (`form_submissions.subject_type`) — and the coworking-session
 * records that end requests and resource consumptions point at
 * (`session_end_requests.sessionable_type`,
 * `resource_consumptions.sessionable_type`). An alias survives a model
 * moving namespace; a stored class name does not.
 *
 * Deliberately `morphMap()`, not `enforceMorphMap()`: Sanctum tokens,
 * notifications and spatie role rows already store User by its full class
 * name, and enforcing a map would stop those rows resolving. For the same
 * reason User must never be added here.
 *
 * Lives in its own provider rather than AppServiceProvider, which imports
 * the Event facade under the same short name as the Event model, and
 * outside App\Domain\Forms, which may not import the domains it serves.
 * FormModelsAreMorphMappedTest fails if a form owner or subject is missing.
 */
class MorphMapServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Relation::morphMap([
            'event' => Event::class,
            'event_attendee' => EventAttendee::class,
            'service_type' => ServiceType::class,
            'service_request' => ServiceRequest::class,
            'booking' => Booking::class,
            'walkin_session' => WalkinSession::class,
        ]);
    }
}
