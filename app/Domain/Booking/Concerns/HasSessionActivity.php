<?php

namespace App\Domain\Booking\Concerns;

use App\Domain\Booking\Enums\SessionEndRequestStatus;
use App\Domain\Booking\Models\SessionEndRequest;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

/** What a coworking session — Booking or WalkinSession — carries besides its time. */
trait HasSessionActivity
{
    public function endRequests(): MorphMany
    {
        return $this->morphMany(SessionEndRequest::class, 'sessionable');
    }

    public function pendingEndRequest(): MorphOne
    {
        return $this->morphOne(SessionEndRequest::class, 'sessionable')
            ->where('status', SessionEndRequestStatus::Pending);
    }
}
