<?php

use App\Modules\Core\Console\MovexaCommands;
use Illuminate\Support\Facades\Schedule;

MovexaCommands::register();

Schedule::command('movexa:scan --inbox')->everyThirtyMinutes()->withoutOverlapping();
Schedule::command('movexa:live-reaper')->everyMinute()->withoutOverlapping();
Schedule::command('movexa:recordings')->everyMinute();
Schedule::command('movexa:cache-prune')->hourly();
Schedule::command('movexa:quarantine-purge')->daily();
Schedule::command('movexa:security-versions')->dailyAt('04:10');
