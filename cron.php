<?php

/**
 * Punto de entrada del cron para paneles que solo aceptan un archivo PHP.
 *
 * Cloudways —y varios hostings administrados— no permiten un comando de shell
 * en sus tareas programadas: solo un archivo .php o una URL. Este arranca
 * Laravel y ejecuta `sistema:tareas`, que corre el programador y vacía la cola
 * de envíos.
 *
 * En el panel se configura como tarea de tipo PHP apuntando a este archivo. La
 * frecuencia la decide el panel: el script no la asume ni la necesita saber.
 * Un candado impide que dos ejecuciones se solapen, se llame cada minuto o
 * cada hora.
 *
 * Va en la raíz del proyecto y NO en public/, así que no se alcanza por web.
 * Aun así, si alguien lo expone, exige el token de despliegue: un endpoint que
 * dispara trabajos no puede quedar abierto.
 */

$raiz = __DIR__;

require $raiz . '/vendor/autoload.php';

$app = require_once $raiz . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Por consola no hace falta nada más: el cron de PHP corre en CLI.
if (PHP_SAPI !== 'cli') {
    $esperado = (string) config('app.deploy_token');
    $recibido = (string) ($_GET['token'] ?? $_SERVER['HTTP_X_DEPLOY_TOKEN'] ?? '');

    // 404 y no 403: sin token válido no se delata que el archivo existe.
    if (strlen($esperado) < 32 || ! hash_equals($esperado, $recibido)) {
        http_response_code(404);
        exit;
    }

    header('Content-Type: text/plain; charset=utf-8');
}

$salida = new Symfony\Component\Console\Output\BufferedOutput();

try {
    $codigo = $kernel->call('sistema:tareas', [], $salida);
} catch (Throwable $e) {
    // Sin esto el cron recibe 0 y da la corrida por buena aunque no hiciera
    // nada: un error de base pasaría inadvertido durante días.
    $codigo = 1;

    $salida->writeln('FALLÓ: ' . $e->getMessage());

    try {
        Illuminate\Support\Facades\Log::error('cron.php falló: ' . $e->getMessage(), [
            'excepcion' => get_class($e),
        ]);
    } catch (Throwable $ignorada) {
        // Si ni siquiera se puede registrar, queda la salida del cron.
    }
}

echo $salida->fetch();

exit($codigo);
