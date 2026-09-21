<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\CompanyProvisioner;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Da de alta una empresa cliente desde la consola.
 *
 * Es el mismo alta del panel de superadministrador, disponible sin navegador:
 * sirve para el primer cliente de una instalación recién puesta (cuando todavía
 * no hay con qué entrar) y para automatizar altas desde un script de venta.
 *
 *   php artisan empresa:crear --nombre="Transportes López" \
 *       --admin-nombre="Ana López" --admin-correo=ana@lopez.cr
 *
 * Sin --admin-clave se genera una y se muestra UNA vez.
 */
class CrearEmpresa extends Command
{
    protected $signature = 'empresa:crear
        {--nombre= : Nombre de la empresa}
        {--cedula= : Cédula jurídica}
        {--correo= : Correo de contacto de la empresa}
        {--admin-nombre= : Nombre del administrador}
        {--admin-correo= : Correo con el que va a entrar}
        {--admin-clave= : Contraseña inicial (si se omite, se genera)}
        {--sede=Sede principal : Nombre de la primera sede}
        {--prefijo= : Prefijo del código guía (2 a 4 letras)}
        {--vence= : Fecha de vencimiento (YYYY-MM-DD)}';

    protected $description = 'Crea una empresa cliente lista para operar, con su administrador y su primera sede';

    /** Si la contraseña la inventó el comando, hay que mostrarla al final. */
    private bool $claveGenerada = false;

    public function handle(CompanyProvisioner $provisioner): int
    {
        $datos = $this->recolectar();

        $validacion = Validator::make($datos, [
            'name'           => 'required|string|max:150',
            'admin_name'     => 'required|string|max:150',
            'admin_email'    => ['required', 'email', 'max:150', Rule::unique('users', 'email')],
            'admin_password' => 'required|string|min:8',
            'branch_name'    => 'required|string|max:150',
            'branch_prefix'  => 'nullable|string|regex:/^[A-Za-z]{2,4}$/',
            'expires_on'     => 'nullable|date',
        ], [
            'admin_email.unique' => 'Ese correo ya tiene cuenta en el sistema. Usá otro para esta empresa.',
            'branch_prefix.regex' => 'El prefijo son de 2 a 4 letras (ej. SJ, LIM).',
        ]);

        if ($validacion->fails()) {
            foreach ($validacion->errors()->all() as $error) {
                $this->components->error($error);
            }

            return self::FAILURE;
        }

        try {
            $empresa = $provisioner->crear($datos);
        } catch (Throwable $e) {
            $this->components->error('No se pudo crear la empresa: ' . $e->getMessage());

            return self::FAILURE;
        }

        $this->mostrarCredenciales($empresa, $datos);

        return self::SUCCESS;
    }

    /**
     * Lo que no vino por opción se pregunta.
     *
     * Preguntar y no fallar: el alta se corre a mano casi siempre, y recordar
     * ocho nombres de opción para un comando que se usa una vez por cliente no
     * es razonable.
     */
    private function recolectar(): array
    {
        $nombre = $this->option('nombre') ?: $this->ask('Nombre de la empresa');
        $adminNombre = $this->option('admin-nombre') ?: $this->ask('Nombre del administrador');
        $adminCorreo = $this->option('admin-correo') ?: $this->ask('Correo del administrador (con el que entra)');

        // Generada y no fija: una instalación en internet con la contraseña del
        // manual dura lo que tarden en probarla.
        $this->claveGenerada = ! $this->option('admin-clave');
        $clave = $this->option('admin-clave') ?: Str::password(14, symbols: false);

        return [
            'name'           => (string) $nombre,
            'legal_name'     => null,
            'identification' => $this->option('cedula'),
            'email'          => $this->option('correo'),
            'phone'          => null,
            'expires_on'     => $this->option('vence'),
            'notes'          => null,
            'admin_name'     => (string) $adminNombre,
            'admin_email'    => (string) $adminCorreo,
            'admin_username' => null,
            'admin_password' => $clave,
            'branch_name'    => (string) $this->option('sede'),
            'branch_prefix'  => $this->option('prefijo'),
        ];
    }

    private function mostrarCredenciales(Company $empresa, array $datos): void
    {
        $sede = $empresa->branches()->first();

        $this->newLine();
        $this->components->info("Empresa «{$empresa->name}» creada y lista para operar.");
        $this->components->twoColumnDetail('Identificador (URL de rastreo)', $empresa->slug);
        $this->components->twoColumnDetail('Primera sede', $sede?->name . ' · prefijo ' . $sede?->prefix);
        $this->components->twoColumnDetail('Entra con', $datos['admin_email']);

        if ($this->claveGenerada) {
            $this->components->twoColumnDetail('Contraseña', "<fg=yellow>{$datos['admin_password']}</>");
            $this->newLine();
            $this->components->warn('ANOTALA: no se vuelve a mostrar. Que la cambie al entrar.');
        }

        $this->newLine();
        $this->components->info('Falta solo lo que tiene el cliente: el certificado .p12, su PIN y '
            . 'las credenciales de ATV. Se cargan desde Configuración de la empresa.');
    }
}
