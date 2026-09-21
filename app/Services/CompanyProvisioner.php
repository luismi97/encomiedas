<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\CashRegister;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\Denomination;
use App\Models\PackageType;
use App\Models\Tax;
use App\Models\User;
use App\Support\CompanyContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Da de alta una empresa lista para trabajar.
 *
 * Es lo que reemplaza a la instalación por cliente. Antes, cada cliente nuevo
 * significaba base nueva, despliegue nuevo y un recorrido a mano por seis
 * pantallas de configuración; los pasos que se olvidaban aparecían después, en
 * forma de «no puedo abrir la caja» o «no me deja crear la guía».
 *
 * Deja armado lo que el sistema NO puede inventar después:
 *   · el administrador con el que se entra por primera vez;
 *   · las denominaciones del arqueo, el IVA y los tipos de bulto;
 *   · la fila de configuración fiscal, vacía y en sandbox;
 *   · y, si le dan nombre, la primera sede con su prefijo y su caja.
 *
 * Lo que sí queda pendiente es lo que solo el cliente tiene: el certificado
 * .p12, el PIN y las credenciales de ATV. Hasta que los cargue, la empresa
 * opera guías y cobra, pero no emite comprobantes —y la pantalla de Hacienda
 * dice exactamente qué le falta—.
 */
class CompanyProvisioner
{
    /** Billetes y monedas de Costa Rica, del mayor al menor. */
    private const DENOMINACIONES = [20000, 10000, 5000, 2000, 1000, 500, 100, 50, 25, 10, 5];

    /** [nombre, frágil] */
    private const TIPOS_DE_BULTO = [
        ['Paquete', false],
        ['Caja', false],
        ['Sobre', false],
        ['Bolsa', false],
        ['Documento', false],
        ['Herramienta', false],
        ['Electrodoméstico', true],
        ['Frágil', true],
    ];

    /**
     * @param  array{
     *     name:string, legal_name?:?string, identification?:?string, email?:?string,
     *     phone?:?string, expires_on?:?string, notes?:?string,
     *     admin_name:string, admin_email:string, admin_username?:?string, admin_password:string,
     *     branch_name?:?string, branch_prefix?:?string, branch_address?:?string
     * }  $datos
     */
    public function crear(array $datos): Company
    {
        // Todo o nada: una empresa a medio armar es peor que ninguna. El caso
        // clásico es el correo repetido, que revienta al crear el usuario
        // cuando la empresa y la sede ya existen y quedan huérfanas.
        return DB::transaction(function () use ($datos) {
            $empresa = Company::create([
                'name'           => $datos['name'],
                'slug'           => Company::slugLibre($datos['name']),
                'legal_name'     => $datos['legal_name'] ?? null,
                'identification' => $datos['identification'] ?? null,
                'email'          => $datos['email'] ?? null,
                'phone'          => $datos['phone'] ?? null,
                'expires_on'     => $datos['expires_on'] ?? null,
                'notes'          => $datos['notes'] ?? null,
                'is_active'      => true,
            ]);

            $this->completar($empresa, $datos);

            return $empresa->refresh();
        });
    }

    /**
     * Termina de armar una empresa que ya existe.
     *
     * Aparte del alta sirve para dos cosas:
     *  - adoptar la empresa que creó la migración al volver multiempresa una
     *    instalación vieja, que llegó con datos pero sin administrador;
     *  - reparar a mano una a la que le falte parte del catálogo.
     *
     * Por eso cada paso pregunta antes: reejecutarlo no duplica nada.
     */
    public function completar(Company $empresa, array $datos): Company
    {
        return DB::transaction(function () use ($empresa, $datos) {
            // Desde acá adentro, todo lo que se cree es de esta empresa: el
            // trait lee el contexto y llena company_id solo. Es lo que evita
            // tener que acordarse en cada create() de abajo.
            CompanyContext::para($empresa, function () use ($datos) {
                CompanySetting::instance()->update([
                    'name'  => $datos['legal_name'] ?? $datos['name'],
                    'email' => $datos['email'] ?? null,
                ]);

                $this->catalogosBase();

                $sede = $this->primeraSede($datos);

                if ($sede) {
                    CashRegister::firstOrCreate(
                        ['branch_id' => $sede->id, 'name' => 'Caja principal'],
                        ['is_active' => true]
                    );
                }

                $this->administrador($datos, $sede);
            });

            return $empresa;
        });
    }

    /**
     * La sede desde la que se empieza a operar.
     *
     * Solo si la piden por nombre. Una sede lleva los códigos de sucursal y
     * terminal que Hacienda le asignó a ESE contribuyente, y el consecutivo de
     * comprobantes se arma con ellos: inventarla en una instalación desatendida
     * —donde nadie los revisó— es peor que no tener ninguna. El panel de altas
     * sí la pide, y ahí el nombre y el prefijo los escribe una persona.
     */
    private function primeraSede(array $datos): ?Branch
    {
        $nombre = trim((string) ($datos['branch_name'] ?? ''));

        if ($nombre === '') {
            return null;
        }

        if ($existente = Branch::orderBy('id')->first()) {
            return $existente;
        }

        return Branch::create([
            'name'           => $nombre,
            'prefix'         => $this->prefijo($datos['branch_prefix'] ?? null, $nombre),
            'sucursal_code'  => '001',
            'terminal_code'  => '00001',
            'address'        => $datos['branch_address'] ?? null,
            'is_active'      => true,
        ]);
    }

    /**
     * El prefijo del código guía (SJ-LIM-00005).
     *
     * Si no lo dan, se deriva del nombre de la sede. Es único por empresa, no
     * por sistema: que otra empresa ya use «SJ» no es problema de esta.
     */
    private function prefijo(?string $pedido, string $nombreDeSede): string
    {
        $base = Str::upper(Str::ascii(trim((string) ($pedido ?: $nombreDeSede))));
        $base = preg_replace('/[^A-Z0-9]/', '', $base) ?: 'SED';
        $base = Str::limit($base, 4, '');

        $candidato = $base;

        for ($i = 2; Branch::where('prefix', $candidato)->exists(); $i++) {
            $candidato = Str::limit($base, 3, '') . $i;
        }

        return $candidato;
    }

    /**
     * Lo que toda empresa necesita el primer día y nadie querría digitar.
     *
     * firstOrCreate y no create: el servicio también se usa para reparar una
     * empresa vieja a la que le falte parte del catálogo.
     */
    private function catalogosBase(): void
    {
        Tax::firstOrCreate(
            ['name' => 'IVA general'],
            ['percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]
        );

        foreach (self::DENOMINACIONES as $orden => $valor) {
            Denomination::firstOrCreate(
                ['value' => $valor],
                ['sort_order' => $orden, 'is_active' => true]
            );
        }

        foreach (self::TIPOS_DE_BULTO as $orden => [$nombre, $fragil]) {
            PackageType::firstOrCreate(
                ['name' => $nombre],
                ['is_fragile' => $fragil, 'sort_order' => $orden, 'is_active' => true]
            );
        }
    }

    /**
     * El administrador con el que se entra por primera vez.
     *
     * No se toca al que ya exista: reescribirle la contraseña dejaría fuera al
     * dueño de la empresa cada vez que alguien repare el catálogo.
     */
    private function administrador(array $datos, ?Branch $sede): ?User
    {
        if (User::where('role', User::ROLE_ADMIN)->where('is_active', true)->exists()) {
            return null;
        }

        return User::create([
            'name'      => $datos['admin_name'],
            'username'  => $this->usuarioLibre($datos['admin_username'] ?: Str::before($datos['admin_email'], '@')),
            'email'     => $datos['admin_email'],
            'password'  => bcrypt($datos['admin_password']),
            'role'      => User::ROLE_ADMIN,
            'branch_id' => $sede?->id,
            'is_active' => true,
        ]);
    }

    /**
     * El nombre de usuario es único en TODO el sistema, no por empresa: es una
     * de las dos formas de entrar al login, y si se repitiera no habría cómo
     * saber a cuál de los dos autenticar.
     */
    private function usuarioLibre(string $base): string
    {
        $base = Str::slug($base, '') ?: 'admin';
        $candidato = $base;

        for ($i = 2; User::withoutGlobalScopes()->where('username', $candidato)->exists(); $i++) {
            $candidato = $base . $i;
        }

        return $candidato;
    }
}
