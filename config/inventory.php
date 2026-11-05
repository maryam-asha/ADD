<?php

use App\Domain\Identity\Models\User;
use App\Domain\Inventory\Adapters\AddLocationResolver;

/*
 * ADD's settings for the inventory package (packages/add/inventory).
 * Anything not set here falls back to the package's own
 * config/inventory.php, option by option.
 */
return [

    'user_model' => User::class,

    // Every asset cost and repair is in one of ADD's currencies.
    'currencies' => [
        'table' => 'currencies',
        'column' => 'code',
    ],

    // A warehouse sits in a branch, or in nothing (an external store).
    'warehouses' => [
        'place_types' => [AddLocationResolver::BRANCH],
    ],

    // Stock is costed in ADD's base currency.
    'stock' => [
        'currency' => 'USD',
    ],
];
