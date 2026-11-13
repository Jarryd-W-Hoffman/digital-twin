<?php

declare(strict_types=1);

use App\Actions\EscalateAlerts;
use App\Actions\SendReports;
use Illuminate\Support\Facades\Schedule;

// Trims readings past their retention (TimescaleDB drops its own chunks).
Schedule::command('model:prune')->daily();

// Tells escalation channels of alerts nobody has acknowledged in time.
Schedule::call(fn () => resolve(EscalateAlerts::class)->handle())
    ->name('escalate-alerts')
    ->everyMinute()
    ->withoutOverlapping();

// Sends the daily and weekly reports that have fallen due.
Schedule::call(fn () => resolve(SendReports::class)->handle())
    ->name('send-reports')
    ->everyFiveMinutes()
    ->withoutOverlapping();
