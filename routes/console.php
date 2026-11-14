<?php

declare(strict_types=1);

use App\Actions\DetectAnomalies;
use App\Actions\EscalateAlerts;
use App\Actions\LearnAnomalyBaselines;
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

// Learns each watched metric's usual band from its latest history, nightly.
Schedule::call(fn () => resolve(LearnAnomalyBaselines::class)->handle())
    ->name('learn-anomaly-baselines')
    ->dailyAt('02:30')
    ->withoutOverlapping();

// Checks the watched metrics against their bands, and for flatlines.
Schedule::call(fn () => resolve(DetectAnomalies::class)->handle())
    ->name('detect-anomalies')
    ->everyFiveMinutes()
    ->withoutOverlapping();
