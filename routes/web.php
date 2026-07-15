<?php

use App\Http\Controllers\Webhooks\BaasFakeWebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::post('/webhooks/baas-fake', BaasFakeWebhookController::class);
