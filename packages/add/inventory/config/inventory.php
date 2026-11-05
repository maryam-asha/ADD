<?php

return [

    // The host's user model: who recorded a movement, a custody, a repair.
    'user_model' => 'App\\Models\\User',

    // The attribute shown as that user's name.
    'user_name_attribute' => 'name',

    'items' => [
        // An item saved without a SKU gets prefix + its id, zero-padded.
        'sku_prefix' => 'ITM-',
        'sku_pad' => 5,
    ],

    'assets' => [
        // Every asset's readable code: prefix + its id, zero-padded. Its QR
        // carries a random token instead, never this.
        'code_prefix' => 'AST-',
        'code_pad' => 6,

        // Most identical assets one registration may create at once.
        'max_batch' => 100,

        // How many days ahead inventory:run-daily warns of maintenance due
        // and of warranties ending.
        'maintenance_lead_days' => 7,
        'warranty_lead_days' => 30,
    ],

    'warehouses' => [
        // The host place types a warehouse may sit in; null = every type
        // its LocationResolver knows. A warehouse may also sit in none.
        'place_types' => null,
    ],

    'stock' => [
        // The one currency stock is costed in (weighted average cost).
        'currency' => 'USD',
    ],

    // Money and stock currency codes: three letters, and one of the host's
    // currencies when `table` names its table.
    'currencies' => [
        'table' => null,
        'column' => 'code',
    ],
];
