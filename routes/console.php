<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly backup — database dump + the durable user uploads, written OFF-BOX. After the
// day's work; withoutOverlapping so a slow dump can never have a second run start on top
// of it. Fails loudly rather than writing to the container's own (ephemeral) disk — see
// config/backup.php.
Schedule::command('backup:run')->dailyAt('02:15')->withoutOverlapping();
