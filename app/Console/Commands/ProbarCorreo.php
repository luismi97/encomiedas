<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Envía un correo de prueba y explica por qué falló, si falla.
 *
 * El envío normal va por la cola: un error de credenciales termina en
 * `failed_jobs` y nunca en pantalla, así que desde fuera se ve igual que si
 * todo funcionara. Esto envía en el acto y muestra la respuesta del servidor.
 */
class ProbarCorreo extends Command
{
    protected $signature = 'correo:probar {destino : Dirección a la que mandar la prueba}';

    protected $description = 'Envía un correo de prueba y diagnostica la configuración';

    public function handle(): int
    {
        $destino = (string) $this->argument('destino');

        if (! filter_var($destino, FILTER_VALIDATE_EMAIL)) {
            $this->components->error("«{$destino}» no es una dirección válida.");

            return self::FAILURE;
        }

        $this->mostrarConfiguracion();

        if (! $this->revisarCacheDeConfiguracion()) {
            return self::FAILURE;
        }

        // Antes de intentar hablar SMTP: si el puerto está cerrado, el error de
        // más abajo es genérico y manda a revisar credenciales que están bien.
        if (! $this->revisarConectividad()) {
            return self::FAILURE;
        }

        return $this->enviar($destino);
    }

    private function mostrarConfiguracion(): void
    {
        $mailer = config('mail.default');
        $config = config("mail.mailers.{$mailer}", []);

        $this->components->info('Configuración que está usando el sistema');
        $this->components->twoColumnDetail('MAIL_MAILER', $mailer ?: '<fg=red>sin definir</>');
        $this->components->twoColumnDetail('host', (string) ($config['host'] ?? '—'));
        $this->components->twoColumnDetail('puerto', (string) ($config['port'] ?? '—'));
        $this->components->twoColumnDetail('scheme', (string) ($config['scheme'] ?? 'según el puerto'));
        $this->components->twoColumnDetail('usuario', (string) ($config['username'] ?? '—'));
        $this->components->twoColumnDetail(
            'contraseña',
            filled($config['password'] ?? null)
                ? '<fg=green>definida (' . strlen((string) $config['password']) . ' caracteres)</>'
                : '<fg=red>VACÍA</>'
        );
        $this->components->twoColumnDetail('remitente', (string) config('mail.from.address'));
        $this->newLine();
    }

    /**
     * El error más frecuente y el más engañoso: editar el .env con la
     * configuración cacheada. El archivo dice una cosa y el sistema usa otra.
     */
    private function revisarCacheDeConfiguracion(): bool
    {
        if (! file_exists($this->rutaCache())) {
            return true;
        }

        $enElEnv = trim((string) env('MAIL_MAILER'));
        $enUso = (string) config('mail.default');

        if ($enElEnv !== '' && $enElEnv !== $enUso) {
            $this->components->error('La configuración está cacheada y NO coincide con el .env.');
            $this->components->twoColumnDetail('el .env dice', $enElEnv);
            $this->components->twoColumnDetail('el sistema usa', $enUso);
            $this->newLine();
            $this->components->info('Corré:  php artisan config:clear');

            return false;
        }

        $this->components->warn('La configuración está cacheada. Si acabás de editar el .env, '
            . 'corré «php artisan config:clear» antes de seguir.');
        $this->newLine();

        return true;
    }

    private function rutaCache(): string
    {
        return base_path('bootstrap/cache/config.php');
    }

    /**
     * ¿Este servidor puede siquiera abrir el puerto del servidor de correo?
     *
     * Los hostings administrados suelen bloquear la salida SMTP, y más aún si
     * el correo vive en otro proveedor. Sin esta comprobación el fallo se
     * confunde con credenciales incorrectas y se pierde tiempo ahí.
     */
    private function revisarConectividad(): bool
    {
        // Con el correo sustituido por un doble de prueba no hay transporte al
        // que conectarse: probar el puerto mediría una red que no se va a usar.
        $raiz = Mail::getFacadeRoot();

        if ($raiz instanceof \Illuminate\Support\Testing\Fakes\MailFake
            || $raiz instanceof \Mockery\MockInterface) {
            return true;
        }

        $mailer = config('mail.default');
        $host = (string) config("mail.mailers.{$mailer}.host");
        $puerto = (int) config("mail.mailers.{$mailer}.port");

        if ($host === '' || $puerto === 0) {
            return true;
        }

        $ip = gethostbyname($host);

        if ($ip === $host && ! filter_var($host, FILTER_VALIDATE_IP)) {
            $this->components->error("El nombre «{$host}» no resuelve a ninguna dirección.");
            $this->components->bulletList(['Revisá MAIL_HOST contra lo que dice el panel del hosting de correo.']);

            return false;
        }

        $this->components->twoColumnDetail("Resolviendo {$host}", "<fg=green>{$ip}</>");

        $inicio = microtime(true);
        $conexion = @fsockopen($ip, $puerto, $codigo, $error, 10);
        $tardo = round((microtime(true) - $inicio) * 1000);

        if ($conexion) {
            fclose($conexion);
            $this->components->twoColumnDetail("Puerto {$puerto}", "<fg=green>abierto ({$tardo} ms)</>");
            $this->newLine();

            return true;
        }

        $this->components->twoColumnDetail("Puerto {$puerto}", '<fg=red>SIN SALIDA</>');
        $this->newLine();
        $this->components->error("Este servidor no puede conectarse a {$host}:{$puerto}.");
        $this->line("  <fg=gray>{$error}</>");
        $this->newLine();

        $this->components->info('No es la contraseña: el tráfico ni sale de esta máquina.');
        $this->components->bulletList([
            'El hosting bloquea la salida SMTP. Es lo habitual cuando el correo está en otro proveedor.',
            'Probá el otro puerto: MAIL_PORT=587 con MAIL_SCHEME=smtp, o MAIL_PORT=465 con MAIL_SCHEME=smtps.',
            'Si ninguno abre, hay que pedirle al hosting que habilite la salida SMTP para este servidor.',
            'Alternativa sin depender de eso: un servicio de envío por API (Resend, Postmark, SES), que usa HTTPS.',
        ]);

        return false;
    }

    private function enviar(string $destino): int
    {
        if (config('mail.default') === 'log') {
            $this->components->error('MAIL_MAILER=log: los correos se escriben en storage/logs y NO salen.');

            return self::FAILURE;
        }

        $this->components->info("Enviando a {$destino}...");

        try {
            Mail::raw(
                "Prueba de correo de Encomiendas CR.\n\n"
                . 'Si estás leyendo esto, el envío funciona. '
                . 'Enviado el ' . now()->format('d/m/Y H:i:s') . '.',
                fn ($m) => $m->to($destino)->subject('Prueba de correo · Encomiendas CR')
            );
        } catch (Throwable $e) {
            $this->explicar($e);

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info('Enviado sin errores. Revisá la bandeja y también la carpeta de spam.');

        return self::SUCCESS;
    }

    /** Traduce el error del servidor a algo accionable. */
    private function explicar(Throwable $e): void
    {
        $mensaje = $e->getMessage();

        // El servidor a veces devuelve la contraseña dentro del error.
        $clave = (string) config('mail.mailers.' . config('mail.default') . '.password');

        if ($clave !== '') {
            $mensaje = str_replace($clave, '********', $mensaje);
        }

        $this->newLine();
        $this->components->error('No se pudo enviar.');
        $this->line("  <fg=gray>{$mensaje}</>");
        $this->newLine();

        foreach ($this->pistas($mensaje, $e) as $pista) {
            $this->components->bulletList([$pista]);
        }
    }

    /** @return array<int,string> */
    private function pistas(string $mensaje, Throwable $e): array
    {
        $m = strtolower($mensaje);

        return match (true) {
            str_contains($m, 'authentication') || str_contains($m, '535') || str_contains($m, 'auth') => [
                'El servidor rechazó el usuario o la contraseña.',
                'MAIL_USERNAME suele ser el correo completo, no solo la parte de antes de la arroba.',
                'Si la contraseña tiene símbolos, dejala entre comillas dobles en el .env.',
            ],
            str_contains($m, 'connection could not be established')
            || str_contains($m, 'connection refused')
            || str_contains($m, 'timed out') => [
                'No se llegó al servidor de correo: el hosting puede tener bloqueada la salida por ese puerto.',
                'Probá con MAIL_PORT=587 y MAIL_SCHEME=smtp (STARTTLS) en vez de 465.',
                'Verificá el host con: getent hosts ' . config('mail.mailers.smtp.host'),
            ],
            str_contains($m, 'ssl') || str_contains($m, 'tls') || str_contains($m, 'certificate') => [
                'El cifrado no coincide con el que espera el servidor.',
                'Con el puerto 465 va MAIL_SCHEME=smtps; con el 587, MAIL_SCHEME=smtp.',
            ],
            str_contains($m, 'sender') || str_contains($m, 'from') || str_contains($m, '550') => [
                'El servidor rechazó el remitente.',
                'MAIL_FROM_ADDRESS tiene que ser un buzón real del mismo dominio, y normalmente el mismo de MAIL_USERNAME.',
            ],
            default => [
                'Revisá host, puerto y credenciales contra lo que muestra el panel del hosting.',
                'Si el hosting bloquea la salida SMTP, hay que pedirle que la habilite.',
            ],
        };
    }
}
