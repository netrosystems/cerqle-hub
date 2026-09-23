<?php

use App\Modules\Automation\Http\Controllers\AutomationController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'client-app'])->prefix('app/automations')->name('client.automations.')->group(function () {
    Route::get('/', [AutomationController::class, 'index'])->name('index');
    Route::post('/', [AutomationController::class, 'store'])->name('store');
    // Metered: each call spends AI credits.
    Route::post('/generate', [AutomationController::class, 'generate'])->middleware('throttle:10,1')->name('generate');
    Route::get('/{automation}/edit', [AutomationController::class, 'edit'])->name('edit');
    Route::put('/{automation}', [AutomationController::class, 'update'])->name('update');
    Route::delete('/{automation}', [AutomationController::class, 'destroy'])->name('destroy');
    Route::get('/{automation}/runs', [AutomationController::class, 'runs'])->name('runs');
    Route::post('/{automation}/runs/{run}/retry', [AutomationController::class, 'retryRun'])->name('runs.retry');
    Route::post('/{automation}/test', [AutomationController::class, 'test'])->name('test');
    Route::post('/{automation}/token', [AutomationController::class, 'generateToken'])->name('generate-token');
});
