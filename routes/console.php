<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Trims readings past their retention (TimescaleDB drops its own chunks).
Schedule::command('model:prune')->daily();
