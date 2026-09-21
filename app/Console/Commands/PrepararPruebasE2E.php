<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\User;
use App\Services\CompanyEraser;
use App\Support\CompanyContext;
use Illuminate\Console\Command;

/**
 * Deja el sistema listo para las pruebas de navegador (Playwright).
 *
 * Hace dos cosas, las dos acotadas:
 *  - se asegura de que exista el superadministrador con una contraseña conocida;
 *  - barre las empresas que dejaron corridas anteriores.
 *
 * No reinicia la base. Las pruebas de navegador corren contra el entorno de
 * desarrollo de verdad y crean sus propias empresas con nombres únicos, así que
 * un migrate:fresh destruiría datos que no son suyos. La limpieza toca
 * ÚNICAMENTE lo que las pruebas crean, reconocido por su identificador.
 *
 * Se niega a correr en producción: acá se fija una contraseña conocida, y eso
 * en un sistema publicado es una puerta abierta.
 */
class PrepararPruebasE2E extends Command
{
    protected $signature = 'e2e:preparar
        {--correo=superadmin@encomienda.test : Correo del superadministrador de pruebas}
        {--clave=password : Contraseña que van a usar las pruebas}
        {--sin-limpiar : Conserva las empresas que dejaron corridas anteriores}';

    protected $description = 'Garantiza el superadministrador con credenciales conocidas para las pruebas de navegador';

    public function handle(): int
    {
        if (app()->environment('production')) {
            $this->components->error('En producción no: esto fija una contraseña conocida.');

            return self::FAILURE;
        }

        $correo = (string) $this->option('correo');
        $clave = (string) $this->option('clave');

        // Sin ámbito global: puede haber una empresa en contexto y el
        // superadministrador justamente no pertenece a ninguna.
        $superadmin = CompanyContext::sinAlcance(
            fn () => User::withoutGlobalScopes()->where('email', $correo)->first()
        );

        if (! $superadmin) {
            $superadmin = new User([
                'name'     => 'Superadministrador de pruebas',
                'username' => 'e2e-superadmin',
                'email'    => $correo,
            ]);
        }

        $superadmin->forceFill([
            'password'  => bcrypt($clave),
            'role'      => User::ROLE_SUPERADMIN,
            'is_active' => true,
        ])->sinEmpresa()->save();

        $this->components->info('Superadministrador listo para las pruebas.');
        $this->components->twoColumnDetail('Usuario', $correo);
        $this->components->twoColumnDetail('Contraseña', $clave);

        if (! $this->option('sin-limpiar')) {
            $this->barrerEmpresasDePrueba();
        }

        return self::SUCCESS;
    }

    /**
     * Borra lo que dejaron las corridas anteriores.
     *
     * Sin esto se acumulan: el listado se pagina, la empresa recién creada
     * aparece en la página tres y las pruebas dejan de encontrar su propia fila.
     *
     * El filtro es el identificador con el que nacen las empresas de prueba
     * («pruebas-…», de crearEmpresa en tests/e2e/apoyo.js). Una empresa de
     * verdad llamada «Pruebas» tendría que llamarse exactamente así y haber
     * sido creada por estas mismas pruebas para caer acá.
     */
    private function barrerEmpresasDePrueba(): void
    {
        $sobrantes = Company::where('slug', 'like', 'pruebas-%')->get();

        if ($sobrantes->isEmpty()) {
            return;
        }

        $eraser = app(CompanyEraser::class);

        foreach ($sobrantes as $empresa) {
            $eraser->borrar($empresa);
        }

        $this->components->twoColumnDetail(
            'Empresas de corridas anteriores',
            '<fg=yellow>' . $sobrantes->count() . ' eliminadas</>'
        );
    }
}
