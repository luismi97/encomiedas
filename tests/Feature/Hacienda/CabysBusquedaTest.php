<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Settings\CompanySettingsForm;
use App\Models\User;
use App\Services\Hacienda\CabysService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class CabysBusquedaTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@prueba.test'],
            [
                'name' => 'Admin de Prueba', 'username' => 'admin_test',
                'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]
        );
    }

    public function test_busca_por_descripcion(): void
    {
        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response([
            'total' => 1,
            'cabys' => [
                ['codigo' => '8511200000000', 'descripcion' => 'Servicios de transporte de encomiendas', 'impuesto' => 13],
            ],
        ], 200)]);

        $results = app(CabysService::class)->search('transporte de encomiendas');

        $this->assertCount(1, $results);
        $this->assertSame('8511200000000', $results[0]['codigo']);
        $this->assertSame(13.0, $results[0]['impuesto']);
    }

    public function test_un_codigo_de_13_digitos_se_consulta_directo(): void
    {
        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response([
            ['codigo' => '8511200000000', 'descripcion' => 'Servicios de transporte', 'impuesto' => 13],
        ], 200)]);

        $hit = app(CabysService::class)->find('8511200000000');

        $this->assertNotNull($hit);
        $this->assertSame('Servicios de transporte', $hit['descripcion']);
    }

    /**
     * El WAF de Hacienda sirve su página de bloqueo con HTTP 200. Tomarla por
     * "sin resultados" —y cachearla— dejaría el buscador muerto 24 horas.
     */
    public function test_la_pagina_de_bloqueo_del_waf_no_se_toma_por_vacio(): void
    {
        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response('<html>Access Denied</html>', 200)]);

        $this->assertNull(app(CabysService::class)->search('transporte'));
    }

    public function test_un_fallo_de_red_devuelve_null_y_no_revienta(): void
    {
        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response([], 503)]);

        $this->assertNull(app(CabysService::class)->search('transporte'));
    }

    public function test_desde_la_pantalla_se_elige_un_codigo(): void
    {
        $this->companySettings();

        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response([
            'cabys' => [
                ['codigo' => '8511200000000', 'descripcion' => 'Servicios de transporte de encomiendas', 'impuesto' => 13],
            ],
        ], 200)]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->set('cabysTerm', 'transporte de encomiendas')
            ->call('searchCabys')
            ->assertSee('Servicios de transporte de encomiendas')
            ->call('useCabys', '8511200000000')
            ->assertSet('default_cabys_code', '8511200000000');
    }

    public function test_si_no_se_puede_consultar_se_avisa_y_se_deja_digitar(): void
    {
        $this->companySettings();

        Http::fake(['*api.hacienda.go.cr/fe/cabys*' => Http::response('<html>bloqueado</html>', 200)]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->set('cabysTerm', 'transporte')
            ->call('searchCabys')
            ->assertSee('No se pudo consultar el catálogo')
            ->assertSet('cabysResults', []);
    }
}
