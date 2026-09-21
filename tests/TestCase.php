<?php

namespace Tests;

use App\Models\Company;
use App\Support\CompanyContext;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * La empresa dueña de todo lo que la prueba cree.
     *
     * Existe para que las pruebas no tengan que saber que el sistema es
     * multiempresa: crean su sucursal y su guía como siempre, y el contexto se
     * encarga de que queden bajo una empresa de verdad. Sin esto, cada fila
     * nacería con company_id nulo y el ámbito global no la encontraría después
     * —una prueba que crea una guía y no la ve, sin decir por qué—.
     *
     * Quien necesite probar el aislamiento crea su segunda empresa y cambia el
     * contexto a mano con CompanyContext::para().
     */
    protected ?Company $empresa = null;

    protected function setUp(): void
    {
        parent::setUp();

        // El contexto es estático y el mismo proceso PHP corre cientos de
        // pruebas: sin limpiarlo, la empresa de una se filtraría a la siguiente.
        CompanyContext::olvidar();

        if ($this->usaBaseDeDatos()) {
            // La que dejó la migración, no una nueva: los catálogos que
            // siembran las migraciones (los tipos de bulto) quedaron a nombre de
            // ESA, y una empresa aparte los vería vacíos.
            $this->empresa = Company::orderBy('id')->first()
                ?? Company::create(['name' => 'Empresa de pruebas', 'slug' => 'empresa-de-pruebas', 'is_active' => true]);

            CompanyContext::usar($this->empresa);
        }
    }

    protected function tearDown(): void
    {
        CompanyContext::olvidar();

        parent::tearDown();
    }

    /**
     * Al autenticar, la empresa pasa a ser la del usuario.
     *
     * Importa para las pruebas de aislamiento: si el contexto fijado en setUp()
     * se quedara mandando, actuar como el usuario de otra empresa igual vería
     * los datos de la primera, y la prueba pasaría sin probar nada.
     */
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);

        CompanyContext::olvidar();

        return $this;
    }

    /** ¿Esta prueba tiene esquema contra el cual crear la empresa? */
    private function usaBaseDeDatos(): bool
    {
        $traits = class_uses_recursive(static::class);

        return isset($traits[RefreshDatabase::class])
            || isset($traits[DatabaseMigrations::class])
            || isset($traits[DatabaseTransactions::class]);
    }
}
