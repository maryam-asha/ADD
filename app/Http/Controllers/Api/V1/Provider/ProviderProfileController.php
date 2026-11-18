<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Http\Controllers\Api\V1\Provider\Concerns\ActsForProvider;
use App\Http\Controllers\Controller;
use App\Http\Resources\ProviderResource;
use Illuminate\Http\Request;

/**
 * The provider this account works for — what the portal shows in its
 * header and profile page. `auth/me` stays about the user only.
 */
class ProviderProfileController extends Controller
{
    use ActsForProvider;

    public function show(Request $request): ProviderResource
    {
        return new ProviderResource($this->provider($request)->loadCount('companies'));
    }
}
