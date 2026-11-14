<?php

namespace Add\Pm\Http\Controllers;

use Add\Pm\Contracts\PeopleDirectory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The picker behind "add a member" and "assign to": people by name. Open to
 * any dashboard account, since choosing someone reveals only their name.
 */
class PeopleController extends Controller
{
    public function index(Request $request, PeopleDirectory $people): JsonResponse
    {
        $term = trim((string) $request->query('q', ''));

        return response()->json(['data' => $people->search($term, 20)]);
    }
}
