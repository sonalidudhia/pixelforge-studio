<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Webhooks can be lost: ask Pixelforge directly about generations that went quiet.
Schedule::command('generations:reconcile')->everyMinute()->withoutOverlapping();
