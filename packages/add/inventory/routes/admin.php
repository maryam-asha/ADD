<?php

use Add\Inventory\Assets\Http\Controllers\AssetController;
use Add\Inventory\Assets\Http\Controllers\CustodyController;
use Add\Inventory\Assets\Http\Controllers\MaintenanceController;
use Add\Inventory\Assets\Http\Controllers\MaintenanceScheduleController;
use Add\Inventory\Authorization\Abilities;
use Add\Inventory\Catalog\Http\Controllers\AttributeDefinitionController;
use Add\Inventory\Catalog\Http\Controllers\CategoryController;
use Add\Inventory\Catalog\Http\Controllers\ItemController;
use Add\Inventory\Catalog\Http\Controllers\LocationController;
use Add\Inventory\Catalog\Http\Controllers\UnitController;
use Add\Inventory\Inventory;
use Add\Inventory\Reports\Http\Controllers\ReportController;
use Add\Inventory\Stock\Http\Controllers\StockController;
use Add\Inventory\Warehouses\Http\Controllers\WarehouseController;
use Illuminate\Support\Facades\Route;

/*
 * Inventory administration. Mounted by Inventory::adminRoutes() inside the
 * host's own authenticated group; each route checks its own ability.
 */

$catalog = [
    'categories' => [CategoryController::class, 'category'],
    'units' => [UnitController::class, 'unit'],
    'items' => [ItemController::class, 'item'],
];

// The package's own places, only while the host has none of its own.
if (Inventory::ownsLocations()) {
    $catalog['locations'] = [LocationController::class, 'location'];
}

Route::middleware('can:'.Abilities::CATALOG_VIEW)->group(function () use ($catalog) {
    foreach ($catalog as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['index', 'show'])->parameters([$uri => $parameter]);
    }
});

Route::middleware('can:'.Abilities::CATALOG_MANAGE)->group(function () use ($catalog) {
    foreach ($catalog as $uri => [$controller, $parameter]) {
        Route::apiResource($uri, $controller)->only(['store', 'update', 'destroy'])->parameters([$uri => $parameter]);
    }

    Route::post('categories/{category}/fields', [AttributeDefinitionController::class, 'store'])->name('fields.store');
    Route::match(['put', 'patch'], 'fields/{attributeDefinition}', [AttributeDefinitionController::class, 'update'])->name('fields.update');
    Route::delete('fields/{attributeDefinition}', [AttributeDefinitionController::class, 'destroy'])->name('fields.destroy');
});

// Assets
Route::middleware('can:'.Abilities::ASSETS_VIEW)->group(function () {
    Route::get('assets', [AssetController::class, 'index'])->name('assets.index');
    Route::get('assets/qr/{token}', [AssetController::class, 'scan'])->name('assets.scan');
    Route::get('assets/{asset}', [AssetController::class, 'show'])->name('assets.show');
    Route::get('custody', [CustodyController::class, 'index'])->name('custody.index');
    Route::get('maintenance', [MaintenanceController::class, 'index'])->name('maintenance.index');
    Route::get('assets/{asset}/maintenance-schedules', [MaintenanceScheduleController::class, 'index'])->name('maintenance-schedules.index');
    Route::get('maintenance-schedules/due', [MaintenanceScheduleController::class, 'due'])->name('maintenance-schedules.due');
});

Route::middleware('can:'.Abilities::ASSETS_MANAGE)->group(function () {
    Route::post('assets', [AssetController::class, 'store'])->name('assets.store');
    Route::match(['put', 'patch'], 'assets/{asset}', [AssetController::class, 'update'])->name('assets.update');
    Route::patch('assets/{asset}/status', [AssetController::class, 'status'])->name('assets.status');
    Route::post('assets/{asset}/move', [AssetController::class, 'move'])->name('assets.move');
});

Route::middleware('can:'.Abilities::ASSETS_CUSTODY)->group(function () {
    Route::post('assets/{asset}/assign', [AssetController::class, 'assign'])->name('assets.assign');
    Route::post('assets/{asset}/return', [AssetController::class, 'takeBack'])->name('assets.return');
});

Route::middleware('can:'.Abilities::ASSETS_MAINTENANCE)->group(function () {
    Route::post('assets/{asset}/maintenance', [MaintenanceController::class, 'open'])->name('maintenance.open');
    Route::post('maintenance/{record}/close', [MaintenanceController::class, 'close'])->name('maintenance.close');
    Route::post('assets/{asset}/maintenance-schedules', [MaintenanceScheduleController::class, 'store'])->name('maintenance-schedules.store');
    Route::match(['put', 'patch'], 'maintenance-schedules/{schedule}', [MaintenanceScheduleController::class, 'update'])->name('maintenance-schedules.update');
    Route::delete('maintenance-schedules/{schedule}', [MaintenanceScheduleController::class, 'destroy'])->name('maintenance-schedules.destroy');
});

Route::post('assets/{asset}/dispose', [AssetController::class, 'dispose'])
    ->middleware('can:'.Abilities::ASSETS_DISPOSE)->name('assets.dispose');

// Warehouses: where stock is kept
Route::middleware('can:'.Abilities::WAREHOUSES_VIEW)->group(function () {
    Route::apiResource('warehouses', WarehouseController::class)->only(['index', 'show']);
});

Route::middleware('can:'.Abilities::WAREHOUSES_MANAGE)->group(function () {
    Route::apiResource('warehouses', WarehouseController::class)->only(['store', 'update', 'destroy']);
});

// Stock
Route::middleware('can:'.Abilities::STOCK_VIEW)->group(function () {
    Route::get('stock/balances', [StockController::class, 'balances'])->name('stock.balances');
    Route::get('stock/movements', [StockController::class, 'movements'])->name('stock.movements');
});

Route::post('stock/receive', [StockController::class, 'receive'])
    ->middleware('can:'.Abilities::STOCK_RECEIVE)->name('stock.receive');
Route::post('stock/issue', [StockController::class, 'issue'])
    ->middleware('can:'.Abilities::STOCK_ISSUE)->name('stock.issue');
Route::post('stock/transfer', [StockController::class, 'transfer'])
    ->middleware('can:'.Abilities::STOCK_TRANSFER)->name('stock.transfer');
Route::post('stock/adjust', [StockController::class, 'adjust'])
    ->middleware('can:'.Abilities::STOCK_ADJUST)->name('stock.adjust');

// Reports
Route::middleware('can:'.Abilities::REPORTS_VIEW)->group(function () {
    Route::get('reports/stock-valuation', [ReportController::class, 'stockValuation'])->name('reports.stock-valuation');
    Route::get('reports/asset-register', [ReportController::class, 'assetRegister'])->name('reports.asset-register');
});
