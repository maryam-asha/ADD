<?php

namespace App\Http\Requests\Admin;

/**
 * The same rules as creating a space — a co_space's capacity is its desks on
 * both paths, never sent (docs/decisions/coworking-seat-pool.md).
 */
class UpdateSpaceRequest extends StoreSpaceRequest {}
