<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Revisa cada minuto el estado de los comprobantes ya enviados a Hacienda.
Schedule::command('hacienda:poll')->everyMinute()->withoutOverlapping();

// Guías sin retirar: avisa y, si está habilitado, desecha. Una vez al día basta
// —el plazo se mide en días— y de madrugada no compite con la operación.
Schedule::command('guias:desecho')->dailyAt('02:30')->withoutOverlapping();

// Corte de crédito. El día lo define cada cliente, así que corre a diario y el
// comando decide a quién le toca: es más fiable que una tarea por cliente.
Schedule::command('credito:corte')->dailyAt('03:00')->withoutOverlapping();
