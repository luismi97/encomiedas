<?php

use App\Http\Controllers\DeployController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Mantenimiento por HTTP
|--------------------------------------------------------------------------
|
| Estas rutas van A PROPOSITO sin el grupo `web`: ese grupo arranca la sesion,
| y con SESSION_DRIVER=database la sesion necesita la base. Un endpoint para
| crear la base que necesita la base para responder no sirve de nada.
|
| Por lo mismo el limite de intentos no usa el middleware `throttle` (que va
| contra la cache, tambien en la base): lo aplica el controlador contra la
| cache de archivos.
|
| Sin DEPLOY_TOKEN en el .env todas responden 404.
|
*/

Route::get('/__deploy/{action}', DeployController::class)
    ->where('action', 'status|db-create|migrate|clear|optimize|queue-restart|queue-work|failed-jobs|hacienda|mail-test|seed')
    ->name('deploy');
