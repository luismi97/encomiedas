<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyProvisioner;
use App\Support\CompanyContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Lo mínimo para arrancar en producción: nada de datos de demostración.
 *
 * DatabaseSeeder encadena DemoDataSeeder, que crea sucursales inventadas,
 * clientes falsos y guías ficticias —y esas guías consumen consecutivos reales
 * de Hacienda—. Correrlo en producción ensucia la operación desde el primer
 * día, y por eso el endpoint de despliegue lo bloquea.
 *
 * Desde que el sistema es multiempresa deja dos cosas distintas:
 *
 *  1. El superadministrador, que es del sistema y no de ninguna empresa. Es
 *     quien después da de alta a cada cliente desde el panel, sin volver a
 *     tocar la consola.
 *  2. La primera empresa con su administrador. Si la migración a multiempresa
 *     ya dejó una —con todo lo que la instalación traía—, se adopta esa en vez
 *     de crear otra al lado.
 *
 * Es re-ejecutable: no pisa nada que ya exista.
 */
class ProduccionSeeder extends Seeder
{
    /** Contraseña generada, para mostrarla una sola vez a quien lo ejecuta. */
    public static ?string $contrasenaGenerada = null;

    /** Correo del administrador creado, o null si ya había uno. */
    public static ?string $correoCreado = null;

    /** Credenciales del superadministrador, si se creó en esta corrida. */
    public static ?string $superadminCorreo = null;
    public static ?string $superadminContrasena = null;

    public function run(): void
    {
        self::$contrasenaGenerada = null;
        self::$correoCreado = null;
        self::$superadminCorreo = null;
        self::$superadminContrasena = null;

        $this->crearSuperadministrador();
        $this->crearPrimeraEmpresa();
    }

    /**
     * El dueño del sistema.
     *
     * Va sin empresa (company_id nulo): esa es su marca, y de ahí sale que vea
     * las de todos. Se crea una sola vez; si ya hay uno no se le toca la
     * contraseña, que dejaría fuera a quien opera el sistema.
     */
    private function crearSuperadministrador(): void
    {
        if (User::withoutGlobalScopes()->where('role', User::ROLE_SUPERADMIN)->exists()) {
            $this->command?->info('Ya existe un superadministrador: no se creó ninguno.');

            return;
        }

        $correo = (string) env('SUPERADMIN_EMAIL', 'superadmin@encomiendas.local');
        $contrasena = (string) env('SUPERADMIN_PASSWORD', '');
        $generada = $contrasena === '';

        if ($generada) {
            $contrasena = Str::password(16, symbols: false);
        }

        $superadmin = new User([
            'name'      => (string) env('SUPERADMIN_NAME', 'Superadministrador'),
            'username'  => $this->usuarioLibre(Str::before($correo, '@') ?: 'superadmin'),
            'email'     => $correo,
            'password'  => bcrypt($contrasena),
            'role'      => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ]);

        // sinEmpresa() y no company_id = null: el relleno automático volvería
        // a ponerle la empresa activa al guardar, y este usuario justamente no
        // pertenece a ninguna.
        $superadmin->sinEmpresa()->save();

        self::$superadminCorreo = $superadmin->email;
        self::$superadminContrasena = $generada ? $contrasena : null;

        $this->command?->info("Superadministrador creado: {$superadmin->email}");
    }

    /**
     * La primera empresa con su administrador.
     *
     * Se ADOPTA la que haya dejado la migración en vez de crear otra: al volver
     * multiempresa una instalación que ya venía funcionando, la migración creó
     * una empresa con todos los datos que existían, y esa es la buena. Crear una
     * segunda dejaría al administrador nuevo mirando un sistema vacío mientras
     * la operación de verdad queda del otro lado.
     *
     * Si esa empresa ya tiene administrador activo no se toca nada: reescribirle
     * la contraseña dejaría fuera al dueño del sistema.
     */
    private function crearPrimeraEmpresa(): void
    {
        $empresa = Company::orderBy('id')->first();

        if ($empresa && $this->yaTieneAdministrador($empresa)) {
            $this->command?->info('Ya existe un administrador activo: no se creó ninguno.');

            return;
        }

        $correo = (string) env('ADMIN_EMAIL', 'admin@encomiendas.local');
        $contrasena = (string) env('ADMIN_PASSWORD', '');
        $generada = $contrasena === '';

        if ($generada) {
            $contrasena = Str::password(16, symbols: false);
        }

        $datos = [
            'name'           => $empresa->name ?? (string) env('COMPANY_NAME', config('app.name', 'Mi empresa')),
            'legal_name'     => $empresa->legal_name ?? null,
            'identification' => $empresa->identification ?? null,
            'email'          => $empresa->email ?? $correo,
            'phone'          => null,
            'expires_on'     => null,
            'notes'          => null,
            'admin_name'     => (string) env('ADMIN_NAME', 'Administrador'),
            'admin_email'    => $correo,
            'admin_username' => null,
            'admin_password' => $contrasena,
            // Sin sede: los códigos de sucursal y terminal se los da Hacienda a
            // cada contribuyente, y el consecutivo de comprobantes se arma con
            // ellos. Se cargan desde la pantalla de Sucursales, con los de verdad.
            'branch_name'    => null,
            'branch_prefix'  => null,
        ];

        $provisioner = app(CompanyProvisioner::class);

        $empresa = $empresa
            ? $provisioner->completar($empresa, $datos)
            : $provisioner->crear($datos);

        self::$correoCreado = $correo;
        self::$contrasenaGenerada = $generada ? $contrasena : null;

        $this->command?->info("Empresa «{$empresa->name}» lista con su administrador: {$correo}");
    }

    /** ¿Esa empresa ya tiene quién la administre? */
    private function yaTieneAdministrador(Company $empresa): bool
    {
        return CompanyContext::para($empresa, fn () => User::query()
            ->where('role', User::ROLE_ADMIN)
            ->where('is_active', true)
            ->exists());
    }

    /** El usuario es único en todo el sistema: si está tomado, se numera. */
    private function usuarioLibre(string $base): string
    {
        $candidato = $base;

        for ($i = 2; User::withoutGlobalScopes()->where('username', $candidato)->exists(); $i++) {
            $candidato = $base . $i;
        }

        return $candidato;
    }
}
