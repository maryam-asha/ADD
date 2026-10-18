<?php

namespace Add\Hr\Recruitment\Http\Controllers\Admin;

use Add\Hr\Recruitment\Http\Requests\MakeOfferRequest;
use Add\Hr\Recruitment\Http\Requests\OfferResponseRequest;
use Add\Hr\Recruitment\Http\Resources\OfferResource;
use Add\Hr\Recruitment\Models\Application;
use Add\Hr\Recruitment\Models\Offer;
use Add\Hr\Recruitment\Services\OfferService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;

class OfferController extends Controller
{
    public function __construct(private readonly OfferService $offers) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return OfferResource::collection(
            Offer::query()
                ->when($request->query('status'), fn ($q, $status) => $q->where('status', $status))
                ->latest('id')
                ->paginate(50)
        );
    }

    public function show(Offer $offer): OfferResource
    {
        return new OfferResource($offer);
    }

    public function store(MakeOfferRequest $request, Application $application): OfferResource
    {
        return new OfferResource($this->offers->make($application, $request->validated(), $request->user()?->getAuthIdentifier())->refresh());
    }

    public function accept(Offer $offer): JsonResponse
    {
        $this->offers->accept($offer);

        return response()->json(['message' => __('hr::recruitment.offer_accepted')]);
    }

    public function decline(OfferResponseRequest $request, Offer $offer): JsonResponse
    {
        $this->offers->decline($offer, $request->validated('note'));

        return response()->json(['message' => __('hr::recruitment.offer_declined')]);
    }

    public function withdraw(Offer $offer): JsonResponse
    {
        $this->offers->withdraw($offer);

        return response()->json(['message' => __('hr::recruitment.offer_withdrawn')]);
    }
}
