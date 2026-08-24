<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Mantenimiento por HTTP para hostings donde entrar por SSH es incómodo.
 *
 *   GET /__deploy/status?token=...        qué falta por migrar, estado de la cola
 *   GET /__deploy/migrate?token=...       corre las migraciones pendientes (--force)
 *   GET /__deploy/clear?token=...         limpia toda la caché (config, rutas, vistas)
 *   GET /__deploy/optimize?token=...      cachea config y rutas (ver nota sobre vistas)
 *   GET /__deploy/queue-restart?token=... obliga al worker a recargar el código
 *   GET /__deploy/queue-work?token=...    procesa la cola pendiente y sale
 *   GET /__deploy/failed-jobs?token=...   los trabajos que agotaron sus reintentos
 *   GET /__deploy/hacienda?token=...      por qué no salen los comprobantes
 *   GET /__deploy/seed?token=...&confirm=1  corre los seeders (destructivo, ver abajo)
 *
 * El token se puede mandar como cabecera `X-Deploy-Token` en vez de query string:
 * es preferible, porque la query string queda escrita en los logs de acceso del
 * servidor y en el historial del navegador.
 *
 * Sin DEPLOY_TOKEN en el .env el endpoint entero responde 404: no existe hasta
 * que alguien lo habilita a propósito.
 */
class DeployController extends Controller
{
    /**
     * Secuencias permitidas. La lista es cerrada a propósito: esto NO ejecuta
     * comandos arbitrarios, solo estas combinaciones concretas.
     */
    private const SEQUENCES = [
        'migrate'       => ['migrate'],
        'clear'         => ['optimize:clear'],
        'optimize'      => ['config:cache', 'route:cache', 'view:clear'],
        'queue-restart' => ['queue:restart'],
    ];

    private const REPORTS = ['status', 'failed-jobs', 'hacienda', 'queue-work', 'seed', 'db-create', 'mail-test', 'db-status', 'migrate-fresh', 'migrate-repair', 'setup'];

    /** Intentos permitidos por minuto y por IP antes de responder 429. */
    private const MAX_INTENTOS = 10;

    public function __invoke(Request $request, string $action)
    {
        $this->abortarSiHayDemasiadosFallos($request);

        $token = config('app.deploy_token');
        $enviado = (string) ($request->header('X-Deploy-Token') ?: $request->query('token'));

        // 404 y no 403: sin token válido, el endpoint no debe ni delatar que existe.
        if (!is_string($token) || $token === '' || strlen($token) < 32 || !hash_equals($token, $enviado)) {
            // Solo cuentan los FALLIDOS: una secuencia legítima de despliegue
            // encadena varias llamadas y no debe quedarse sin cupo.
            $this->registrarFallo($request);

            abort(404);
        }

        abort_unless(isset(self::SEQUENCES[$action]) || in_array($action, self::REPORTS, true), 404);

        Log::warning('Endpoint de mantenimiento usado', [
            'accion' => $action,
            'ip'     => $request->ip(),
            'agente' => $request->userAgent(),
        ]);

        return match ($action) {
            'status'      => $this->status(),
            'db-create'   => $this->dbCreate(),
            'failed-jobs' => $this->failedJobs($request),
            'hacienda'    => $this->hacienda(),
            'db-status'    => $this->dbStatus(),
            'migrate-fresh' => $this->migrateFresh($request),
            'migrate-repair' => $this->migrateRepair($request),
            'setup'        => $this->setup($request),
            'mail-test'   => $this->mailTest($request),
            'queue-work'  => $this->queueWork(),
            'seed'        => $this->seed($request),
            default       => $this->runSequence($action),
        };
    }

    /**
     * Límite de intentos contra la caché de ARCHIVOS, no la de la aplicación.
     *
     * El middleware `throttle` va contra el almacén por defecto, que aquí es la
     * base: usarlo dejaría el endpoint inservible justo cuando más se necesita,
     * que es cuando la base todavía no existe.
     */
    private function abortarSiHayDemasiadosFallos(Request $request): void
    {
        abort_if(
            (int) Cache::store('file')->get($this->claveIntentos($request), 0) >= self::MAX_INTENTOS,
            429,
            'Demasiados intentos fallidos. Esperá un minuto.'
        );
    }

    private function registrarFallo(Request $request): void
    {
        $clave = $this->claveIntentos($request);
        $cache = Cache::store('file');

        $cache->put($clave, (int) $cache->get($clave, 0) + 1, now()->addMinute());

        Log::warning('Intento fallido contra el endpoint de mantenimiento', ['ip' => $request->ip()]);
    }

    private function claveIntentos(Request $request): string
    {
        return 'deploy_fallos_' . sha1((string) $request->ip());
    }

    /**
     * Crea la base declarada en el .env. Es lo primero que hace falta en un
     * hosting sin SSH: sin base, ni migrate ni nada más tiene dónde correr.
     */
    private function dbCreate(): mixed
    {
        try {
            $codigo = Artisan::call('db:create');
        } catch (Throwable $e) {
            return response()->json([
                'creada' => false,
                'error'  => $e->getMessage(),
                'pista'  => 'Suele ser que el usuario del .env no tiene permiso CREATE DATABASE. '
                    . 'En hostings administrados la base se crea desde el panel.',
            ], 500);
        }

        $salida = trim(Artisan::output());

        return response()->json([
            'creada'  => $codigo === 0,
            'salida'  => $salida,
            'siguiente' => $codigo === 0 ? '/__deploy/migrate' : null,
        ], $codigo === 0 ? 200 : 500);
    }

    private function runSequence(string $action): mixed
    {
        $salida = [];

        foreach (self::SEQUENCES[$action] as $comando) {
            Artisan::call($comando, $comando === 'migrate' ? ['--force' => true] : []);
            $salida[$comando] = trim(Artisan::output());
        }

        return response()->json(['accion' => $action, 'ejecutado' => $salida]);
    }

    /** Qué falta por aplicar y cómo está la cola, sin cambiar nada. */
    private function status(): mixed
    {
        Artisan::call('migrate:status');
        $migraciones = trim(Artisan::output());

        $pendientes = substr_count($migraciones, 'Pending');

        return response()->json([
            'entorno'             => app()->environment(),
            'debug'               => config('app.debug'),
            'migraciones_pendientes' => $pendientes,
            'cola' => [
                'conexion'  => config('queue.default'),
                'en_espera' => DB::table('jobs')->count(),
                'fallidos'  => DB::table('failed_jobs')->count(),
                'retry_after' => config('queue.connections.database.retry_after'),
            ],
            'comprobantes' => ElectronicInvoice::selectRaw('status, count(*) c')
                ->groupBy('status')->pluck('c', 'status'),
            'detalle_migraciones' => $migraciones,
        ]);
    }

    /**
     * Qué hay realmente en la base, sin pasar por ningún modelo.
     *
     * `migrate` falla con «Table users already exists» cuando el esquema y la
     * tabla `migrations` no coinciden: las tablas están, pero Laravel no tiene
     * registro de haberlas creado. Para decidir qué hacer hace falta ver las
     * dos listas, y ningún comando de Artisan las muestra juntas.
     */
    private function dbStatus(): mixed
    {
        $base = DB::connection()->getDatabaseName();

        try {
            // getTableListing() y no «SHOW TABLES»: ese es de MySQL y el mismo
            // endpoint tiene que responder con cualquier motor.
            $tablas = collect(Schema::getTableListing())
                ->map(fn ($t) => is_array($t) ? ($t['name'] ?? '') : $t)
                ->filter()
                ->sort()
                ->values();
        } catch (\Throwable $e) {
            return response()->json([
                'base'  => $base,
                'error' => 'No se pudo leer el esquema: ' . $e->getMessage(),
            ], 500);
        }

        $tieneMigrations = $tablas->contains('migrations');

        $aplicadas = $tieneMigrations
            ? collect(DB::table('migrations')->orderBy('id')->pluck('migration'))
            : collect();

        $archivos = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($ruta) => basename($ruta, '.php'))
            ->sort()
            ->values();

        $pendientes = $archivos->diff($aplicadas)->values();

        return response()->json([
            'base' => $base,
            'tablas' => [
                'cantidad' => $tablas->count(),
                'lista'    => $tablas,
            ],
            'migraciones' => [
                'tabla_existe' => $tieneMigrations,
                'registradas'  => $aplicadas->count(),
                'archivos'     => $archivos->count(),
                'pendientes'   => $pendientes->count(),
                'lista_pendientes' => $pendientes,
            ],
            // El caso que produce «Table users already exists».
            'diagnostico' => $this->diagnosticar($tablas, $aplicadas, $pendientes),
        ]);
    }

    private function diagnosticar($tablas, $aplicadas, $pendientes): string
    {
        if ($tablas->isEmpty()) {
            return 'La base está vacía: corré /__deploy/migrate.';
        }

        if ($aplicadas->isEmpty()) {
            return 'Hay ' . $tablas->count() . ' tablas pero NINGUNA migración registrada: '
                . 'el esquema viene de otra instalación. `migrate` va a chocar con las tablas que ya existen. '
                . 'Si no hay datos que conservar, usá /__deploy/migrate-fresh (BORRA TODO). '
                . 'Si hay datos, hay que registrar a mano las migraciones ya aplicadas.';
        }

        if ($pendientes->isEmpty()) {
            return 'Todo al día: no hay migraciones pendientes.';
        }

        return $pendientes->count() . ' migraciones pendientes. Corré /__deploy/migrate.';
    }

    /**
     * Prepara una instalación nueva: configuración, IVA y un administrador.
     *
     * Aparte de `seed` a propósito: aquel corre DemoDataSeeder, que inventa
     * sucursales y guías y consume consecutivos reales de Hacienda. Este solo
     * deja lo imprescindible para poder entrar por primera vez.
     *
     * Sin consola no hay otra forma de crear el primer usuario, y sin usuario
     * el sistema recién migrado no se puede abrir.
     */
    private function setup(Request $request): mixed
    {
        if (! $request->boolean('confirm')) {
            return response()->json([
                'error' => 'Crea la configuración inicial, el IVA y un usuario administrador. '
                    . 'No crea datos de demostración ni toca lo que ya exista. '
                    . 'Agregá &confirm=1 para ejecutarlo.',
            ], 428);
        }

        Artisan::call('db:seed', [
            '--class' => \Database\Seeders\ProduccionSeeder::class,
            '--force' => true,
        ]);

        $contrasena = \Database\Seeders\ProduccionSeeder::$contrasenaGenerada;
        $correo = \Database\Seeders\ProduccionSeeder::$correoCreado;

        return response()->json(array_filter([
            'listo'   => true,
            'admin_creado' => $correo,
            // Se muestra una sola vez: no queda guardada en ningún lado.
            'contrasena_generada' => $contrasena,
            'aviso' => $contrasena
                ? 'ANOTÁ LA CONTRASEÑA: no se vuelve a mostrar. Cambiala al entrar.'
                : ($correo
                    ? 'La contraseña es la de ADMIN_PASSWORD en el .env.'
                    : 'Ya había un administrador activo: no se creó ninguno.'),
            'siguiente' => 'Entrá al sistema y cargá tus sucursales con sus códigos de Hacienda.',
        ], fn ($v) => $v !== null));
    }

    /**
     * Reconcilia la tabla `migrations` con el esquema que ya existe.
     *
     * Laravel decide qué correr comparando NOMBRES DE ARCHIVO contra la tabla
     * `migrations`; nunca mira el esquema. Cuando quedan archivos de una
     * instalación anterior —las migraciones por defecto de Laravel 10, que la
     * 11 consolidó en 0001_01_01_*— aparecen como pendientes y al ejecutarse
     * chocan con tablas que ya están, y `migrate` no avanza más.
     *
     * La reparación registra como aplicadas SOLO las migraciones cuyas tablas
     * ya existen todas. No borra archivos ni toca datos, y una migración que
     * de verdad falte queda intacta para correr normalmente.
     */
    private function migrateRepair(Request $request): mixed
    {
        $aplicadas = collect(DB::table('migrations')->pluck('migration'));

        $pendientes = collect(glob(database_path('migrations/*.php')))
            ->map(fn ($ruta) => ['archivo' => $ruta, 'nombre' => basename($ruta, '.php')])
            ->reject(fn ($m) => $aplicadas->contains($m['nombre']))
            ->values();

        if ($pendientes->isEmpty()) {
            return response()->json([
                'reparado' => false,
                'mensaje'  => 'No hay migraciones pendientes: no hay nada que reconciliar.',
            ]);
        }

        $yaSatisfechas = [];
        $porCorrer = [];

        foreach ($pendientes as $m) {
            $tablas = $this->tablasQueCrea($m['archivo']);

            // Solo se da por aplicada si crea tablas y TODAS existen ya. Una
            // migración que altera o que crea algo ausente se deja correr.
            $satisfecha = $tablas !== [] && collect($tablas)->every(fn ($t) => Schema::hasTable($t));

            if ($satisfecha) {
                $yaSatisfechas[] = ['migracion' => $m['nombre'], 'tablas' => $tablas];
            } else {
                $porCorrer[] = ['migracion' => $m['nombre'], 'tablas' => $tablas];
            }
        }

        if (! $request->boolean('confirm')) {
            return response()->json([
                'reparado' => false,
                'se_marcarian_como_aplicadas' => $yaSatisfechas,
                'se_dejarian_para_correr'     => $porCorrer,
                'siguiente' => $yaSatisfechas === []
                    ? 'Ninguna migración pendiente crea tablas que ya existan: corré /__deploy/migrate.'
                    : 'Revisá la lista y agregá &confirm=1 para registrarlas. No se borra nada.',
            ], 428);
        }

        if ($yaSatisfechas !== []) {
            $lote = (int) DB::table('migrations')->max('batch') + 1;

            DB::table('migrations')->insert(
                collect($yaSatisfechas)
                    ->map(fn ($m) => ['migration' => $m['migracion'], 'batch' => $lote])
                    ->all()
            );
        }

        return response()->json([
            'reparado'  => true,
            'marcadas'  => collect($yaSatisfechas)->pluck('migracion'),
            'pendientes_reales' => collect($porCorrer)->pluck('migracion'),
            'siguiente' => $porCorrer === []
                ? 'Listo. Verificá con /__deploy/db-status.'
                : 'Ahora corré /__deploy/migrate para las que sí faltan.',
        ]);
    }

    /**
     * Qué tablas crea una migración, leyendo el archivo.
     *
     * Se mira el texto y no se ejecuta nada: ejecutarla para averiguarlo es
     * justo lo que revienta.
     *
     * @return array<int,string>
     */
    private function tablasQueCrea(string $archivo): array
    {
        $codigo = @file_get_contents($archivo);

        if ($codigo === false) {
            return [];
        }

        preg_match_all(
            '/Schema::(?:connection\([^)]*\)->)?create\(\s*[\'"]([^\'"]+)[\'"]/',
            $codigo,
            $coincidencias
        );

        return array_values(array_unique($coincidencias[1] ?? []));
    }

    /**
     * Reconstruye el esquema desde cero. BORRA TODAS LAS TABLAS.
     *
     * Existe porque una base con el esquema desalineado no se arregla con
     * `migrate`, y sin consola no hay otra forma de salir. Pide una
     * confirmación que no se teclea por accidente, y no siembra datos: eso es
     * decisión aparte.
     */
    private function migrateFresh(Request $request): mixed
    {
        if ($request->query('confirm') !== 'BORRAR-TODO') {
            return response()->json([
                'error' => 'Esto BORRA TODAS LAS TABLAS y las vuelve a crear vacías. '
                    . 'Se pierden guías, clientes, cajas y comprobantes. '
                    . 'Si estás seguro, agregá &confirm=BORRAR-TODO a la dirección.',
                'antes_de_seguir' => 'Consultá /__deploy/db-status para ver qué hay en la base.',
            ], 428);
        }

        Artisan::call('migrate:fresh', ['--force' => true]);

        return response()->json([
            'accion' => 'migrate-fresh',
            'aviso'  => 'Esquema reconstruido. La base quedó VACÍA: no hay usuarios. '
                . 'Creá el primero antes de poder entrar.',
            'salida' => trim(Artisan::output()),
        ]);
    }

    private function failedJobs(Request $request): mixed
    {
        $limite = min((int) $request->query('limit', 10), 50);

        $fallidos = DB::table('failed_jobs')
            ->latest('failed_at')
            ->limit($limite)
            ->get()
            ->map(fn ($j) => [
                'id'        => $j->uuid,
                'fallo_en'  => $j->failed_at,
                'excepcion' => substr($j->exception, 0, 500),
            ]);

        return response()->json(['total' => DB::table('failed_jobs')->count(), 'ultimos' => $fallidos]);
    }

    /** Checklist de por qué un comprobante no sale, sin exponer credenciales. */
    private function hacienda(): mixed
    {
        $s = CompanySetting::instance();

        return response()->json([
            'listo_para_emitir' => $s->isReady(),
            'requisitos' => [
                'habilitado'          => (bool) $s->enabled,
                'cedula'              => filled($s->identification_number),
                'certificado_cargado' => filled($s->certificate_path),
                'pin_legible'         => filled($s->decryptedOrNull('certificate_pin')),
                'usuario_atv_legible' => filled($s->decryptedOrNull('atv_username')),
                'clave_atv_legible'   => filled($s->decryptedOrNull('atv_password')),
            ],
            'campos_ilegibles' => $s->undecryptableFields(),
            'ambiente'         => $s->effectiveEnvironment(),
            'hacienda_live'    => (bool) config('hacienda.live'),
            'por_estado'       => ElectronicInvoice::selectRaw('status, count(*) c')
                ->groupBy('status')->pluck('c', 'status'),
            'ultimos_errores'  => ElectronicInvoice::whereIn('status', ['error', 'rejected'])
                ->latest()->limit(5)
                ->get(['clave', 'status', 'error_message'])
                ->map(fn ($e) => [
                    'clave'  => $e->clave,
                    'estado' => $e->status,
                    'error'  => $e->error_message,
                ]),
        ]);
    }

    /**
     * Vacía la cola desde el navegador. Es el plan B cuando no hay worker ni
     * cron: no lo reemplaza, porque solo procesa lo que hay en este momento.
     */
    /**
     * Manda un correo de prueba y dice qué pasó.
     *
     * Sin SSH no hay forma de saber por qué no sale un correo: el envío va por
     * la cola, así que un error de credenciales termina en failed_jobs y no en
     * pantalla. Esto envía en el acto —sin cola— y devuelve el error tal cual
     * lo dio el servidor de correo.
     */
    private function mailTest(Request $request): mixed
    {
        $destino = (string) $request->query('to', '');

        if (! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            return response()->json([
                'error' => 'Falta &to=correo@ejemplo.com: es la dirección a la que se manda la prueba.',
            ], 422);
        }

        $mailer = config('mail.default');

        // El caso más común y el más confuso: el sistema responde «enviado» y
        // el correo se escribió en storage/logs.
        if ($mailer === 'log') {
            return response()->json([
                'enviado'  => false,
                'mailer'   => 'log',
                'problema' => 'MAIL_MAILER=log: los correos se escriben en storage/logs y NO salen. '
                    . 'Cambialo a smtp en el .env y volvé a correr /__deploy/clear.',
            ], 409);
        }

        $config = config('mail.mailers.' . $mailer, []);

        try {
            Mail::raw(
                "Prueba de correo de Encomiendas CR.

"
                . 'Si estás leyendo esto, el envío funciona. '
                . 'Enviado el ' . now()->format('d/m/Y H:i:s') . '.',
                fn ($m) => $m->to($destino)->subject('Prueba de correo · Encomiendas CR')
            );
        } catch (\Throwable $e) {
            return response()->json([
                'enviado'  => false,
                'mailer'   => $mailer,
                // Sin la contraseña: el mensaje del servidor a veces la incluye.
                'problema' => str_replace((string) ($config['password'] ?? '@@nada@@'), '***', $e->getMessage()),
                'revisar'  => [
                    'host'     => $config['host'] ?? null,
                    'port'     => $config['port'] ?? null,
                    'username' => $config['username'] ?? null,
                    'from'     => config('mail.from.address'),
                ],
            ], 502);
        }

        return response()->json([
            'enviado' => true,
            'a'       => $destino,
            'mailer'  => $mailer,
            'desde'   => config('mail.from.address'),
            'host'    => $config['host'] ?? null,
            'nota'    => 'Revisá la bandeja y también la carpeta de spam.',
        ]);
    }

    private function queueWork(): mixed
    {
        $antes = DB::table('jobs')->count();

        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time'        => 50,
            '--no-interaction'  => true,
        ]);

        return response()->json([
            'procesados_aprox' => $antes - DB::table('jobs')->count(),
            'quedan_en_cola'   => DB::table('jobs')->count(),
            'fallidos'         => DB::table('failed_jobs')->count(),
            'salida'           => trim(Artisan::output()),
        ]);
    }

    /**
     * Los seeders sobrescriben la configuración de la empresa con datos de
     * demostración y crean facturas ficticias que consumen consecutivos reales
     * de Hacienda. Por eso pide confirmación explícita y se niega en producción.
     */
    private function seed(Request $request): mixed
    {
        if (app()->environment('production')) {
            return response()->json([
                'error' => 'Los seeders no corren en producción desde aquí: sobrescriben la configuración '
                    . 'de la empresa con datos de demostración y queman consecutivos de Hacienda. '
                    . 'Si de verdad hace falta, corralo por SSH.',
            ], 409);
        }

        if (!$request->boolean('confirm')) {
            return response()->json([
                'error' => 'Falta &confirm=1. Esto sobrescribe la configuración de la empresa con datos '
                    . 'de demostración y crea facturas ficticias.',
            ], 428);
        }

        Artisan::call('db:seed', ['--force' => true]);

        return response()->json(['accion' => 'seed', 'salida' => trim(Artisan::output())]);
    }
}
