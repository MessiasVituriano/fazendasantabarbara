<?php

use Illuminate\Support\Facades\Schedule;

// RF03: desmame automatizado. Em produção, o cron do servidor deve chamar
// `php artisan schedule:run` a cada minuto.
Schedule::command('pecuaria:desmame')
    ->dailyAt('01:00')
    ->withoutOverlapping()
    ->onOneServer();
