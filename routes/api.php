<?php

use App\Http\Controllers\Api\HouseholdApiController;
use App\Http\Middleware\AuthenticateService;
use Illuminate\Support\Facades\Route;

// Apps authenticate with HTTP Basic: service key and service secret (docs/HOUSEHOLDS-API.md).
Route::middleware(['throttle:120,1', AuthenticateService::class])->group(function () {
    Route::get('households', [HouseholdApiController::class, 'index'])->name('api.households.index');
    Route::get('households/import', [HouseholdApiController::class, 'importStatus'])->name('api.households.import.status');
    Route::post('households/import', [HouseholdApiController::class, 'import'])->name('api.households.import');
    Route::get('households/{uuid}', [HouseholdApiController::class, 'show'])->whereUuid('uuid')->name('api.households.show');
    Route::put('households/{uuid}/shares', [HouseholdApiController::class, 'shares'])->whereUuid('uuid')->name('api.households.shares');
});
