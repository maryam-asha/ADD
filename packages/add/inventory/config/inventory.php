<?php

return [

    // The host's user model: who recorded a movement, a custody, a repair.
    'user_model' => 'App\\Models\\User',

    // The attribute shown as that user's name.
    'user_name_attribute' => 'name',

    'items' => [
        // Who sets an item's code: 'auto' (always numbered here, never typed),
        // 'on_create' (typed when the item is made, numbered when left out,
        // fixed after) or 'editable' (typed and changed at any time).
        'sku_mode' => 'on_create',

        // A numbered code is the prefix and the next number of the package's
        // own counter (not the item's id), zero-padded to sku_pad digits:
        // ITM-00001, ITM-00002… The characters are the host's to choose.
        'sku_prefix' => 'ITM-',
        'sku_pad' => 5,
    ],

    'assets' => [
        // Every asset's readable code: prefix + its id, zero-padded. Its QR
        // carries a random token instead, never this.
        'code_prefix' => 'AST-',
        'code_pad' => 6,

        // Whether an asset may sit in a warehouse — bought and stored before
        // it is handed out (moved to where it is used).
        'store_in_warehouses' => false,

        // How assets may be depreciated: 'straight_line' (evenly each month)
        // and 'declining_balance' (twice that rate on what is left). An asset
        // takes one of these; the first is the default.
        'depreciation_methods' => ['straight_line'],

        // Whether an asset category may be marked intangible (a software
        // licence): its assets sit nowhere, take no maintenance, and are
        // amortized on a straight line with no salvage.
        'intangible' => false,

        // Whether capital additions may be recorded on an asset: money spent
        // on it that raises its cost, depreciated over what is left of its life.
        'capital_additions' => false,

        // Whether a registered asset is a draft until someone holding
        // inventory.assets.approve approves it into service.
        'drafts' => false,

        // Most identical assets one registration may create at once.
        'max_batch' => 100,

        // Most lines (asset items) one registration in a place may carry.
        'max_lines' => 50,

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
