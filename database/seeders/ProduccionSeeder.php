<?php

namespace Database\Seeders;

use App\Models\CompanySetting;
use App\Models\Tax;
use App\Models\User;
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
 * Este seeder solo deja lo que no se puede configurar sin haber entrado antes:
 * la fila de configuración, el IVA y un administrador. Las sucursales NO se
 * crean: llevan códigos de Hacienda propios de cada empresa y se cargan desde
 * la pantalla de Sucursales.
 *
 * Es re-ejecutable: no pisa nada que ya exista.
 */
class ProduccionSeeder extends Seeder
{
    /** Contraseña generada, para mostrarla una sola vez a quien lo ejecuta. */
    public static ?string $contrasenaGenerada = null;

    /** Correo del administrador creado, o null si ya había uno. */
    public static ?string $correoCreado = null;

    public function run(): void
    {
        self::$contrasenaGenerada = null;
        self::$correoCreado = null;

        CompanySetting::instance();

        // Sin un impuesto por defecto, cada guía habría que marcarla a mano.
        Tax::firstOrCreate(
            ['name' => 'IVA general'],
            ['percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]
        );

        $this->crearAdministrador();
    }

    /**
     * Un administrador, solo si no hay ninguno activo.
     *
     * No se toca al que ya exista: reescribirle la contraseña dejaría fuera al
     * dueño del sistema cada vez que alguien reejecute el seeder.
     */
    private function crearAdministrador(): void
    {
        $yaHay = User::where('role', User::ROLE_ADMIN)->where('is_active', true)->exists();

        if ($yaHay) {
            $this->command?->info('Ya existe un administrador activo: no se creó ninguno.');

            return;
        }

        $correo = (string) env('ADMIN_EMAIL', 'admin@encomiendas.local');
        $usuario = Str::before($correo, '@') ?: 'admin';

        // Del .env si está; si no, una generada. Nunca una fija: un sistema en
        // internet con la contraseña del manual dura lo que tarden en probarla.
        $contrasena = (string) env('ADMIN_PASSWORD', '');
        $generada = $contrasena === '';

        if ($generada) {
            $contrasena = Str::password(16, symbols: false);
        }

        $admin = User::create([
            'name'      => (string) env('ADMIN_NAME', 'Administrador'),
            'username'  => $this->usuarioLibre($usuario),
            'email'     => $correo,
            'password'  => bcrypt($contrasena),
            'role'      => User::ROLE_ADMIN,
            'is_active' => true,
        ]);

        self::$correoCreado = $admin->email;
        self::$contrasenaGenerada = $generada ? $contrasena : null;

        $this->command?->info("Administrador creado: {$admin->email}");

        if ($generada) {
            $this->command?->warn("Contraseña generada: {$contrasena}");
            $this->command?->warn('Anotala: no se vuelve a mostrar.');
        }
    }

    /** El usuario es único: si el derivado del correo está tomado, se numera. */
    private function usuarioLibre(string $base): string
    {
        $candidato = $base;

        for ($i = 2; User::where('username', $candidato)->exists(); $i++) {
            $candidato = $base . $i;
        }

        return $candidato;
    }
}
