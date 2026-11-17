<?php

use App\Http\Controllers\PixelforgeWebhookController;
use App\Http\Middleware\VerifyPixelforgeSignature;
use Illuminate\Support\Facades\Route;

/*
| Machine-to-machine routes. Loaded outside the "web" middleware group: no
| session, no cookies and no CSRF token, which Pixelforge can't send anyway.
| Every request must carry a valid Pixelforge signature instead.
*/

Route::post('webhooks/pixelforge', PixelforgeWebhookController::class)
    ->middleware(VerifyPixelforgeSignature::class)
    ->name('webhooks.pixelforge');
