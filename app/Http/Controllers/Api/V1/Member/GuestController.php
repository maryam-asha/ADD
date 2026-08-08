<?php

namespace App\Http\Controllers\Api\V1\Member;

use App\Domain\Identity\Enums\ConsentSubjectType;
use App\Domain\Identity\Enums\ConsentType;
use App\Domain\Identity\Models\Consent;
use App\Domain\Identity\Models\Guest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Member\StoreGuestRequest;
use App\Http\Resources\GuestResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * A guest has no account and no direct permissions — every request goes
 * through the hosting member (PRD decision #9). Scoped throughout to the
 * authenticated member's own guests; there is no admin visibility into this
 * list in this phase.
 */
class GuestController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return GuestResource::collection(
            $request->user()->hostedGuests()->latest()->get()
        );
    }

    /**
     * Submitting a guest's data on their behalf is itself a privacy-relevant
     * act (PRD §5.11: "بيانات الضيوف التي يقدّمها العضو المستضيف نيابةً
     * عنهم") — recording the consent is not optional, so it happens in the
     * same request rather than as a separate step the host could skip.
     */
    public function store(StoreGuestRequest $request): GuestResource
    {
        $guest = $request->user()->hostedGuests()->create($request->validated());

        Consent::create([
            'subject_type' => ConsentSubjectType::User,
            'subject_id' => $request->user()->id,
            'consent_type' => ConsentType::GuestDataOnBehalf,
            'granted_at' => now(),
        ]);

        return new GuestResource($guest);
    }

    public function destroy(Request $request, Guest $guest): Response
    {
        Gate::allowIf($guest->hosting_user_id === $request->user()->id);

        $guest->delete();

        return response()->noContent();
    }
}
