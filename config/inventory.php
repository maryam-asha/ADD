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

    // ADD has no coding scheme of its own: every item code is numbered
    // (ITM-00001) and none is typed.
    'items' => [
        'sku_mode' => 'auto',
    ],

    // Assets go straight to the place they are used; turn this on when ADD
    // starts storing them in a warehouse before handing them out.
    'assets' => [
        'store_in_warehouses' => false,
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
