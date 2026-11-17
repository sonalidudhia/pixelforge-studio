<?php

use App\Http\Controllers\GenerationController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth'])->group(function () {
    // The studio is the home page after signing in.
    Route::redirect('dashboard', 'generations')->name('dashboard');

    Route::get('generations', [GenerationController::class, 'index'])->name('generations.index');

    Route::get('generations/estimate', [GenerationController::class, 'estimate'])
        ->middleware('throttle:generation-estimates')
        ->name('generations.estimate');

    Route::post('generations', [GenerationController::class, 'store'])
        ->middleware('throttle:generation-submits')
        ->name('generations.store');
});

require __DIR__.'/settings.php';
