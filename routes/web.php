<?php

use App\Http\Controllers\LedgerController;
use App\Http\Controllers\Webhooks\BaasFakeWebhookController;
use App\Http\Controllers\Webhooks\LiquidityFakeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/operations/{operationId}/ledger', [LedgerController::class, 'show']);

Route::post('/webhooks/baas-fake', BaasFakeWebhookController::class);
Route::post('/webhooks/liquidity-fake', LiquidityFakeWebhookController::class);
