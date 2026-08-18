<?php

namespace Tests\Feature\Rastreo;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class PortalPublicoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->usuario = User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(): Invoice
    {
        return Invoice::withoutGlobalScopes()->create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta Solano', 'sender_phone' => '8811-2233',
            'recipient_name' => 'José Fernández', 'recipient_phone' => '7011-2233',
            'recipient_email' => 'jose@cliente.test',
            'recipient_identification' => '112340567',
            'subtotal' => 45000, 'discount_amount' => 0, 'tax_total' => 5850, 'total' => 50850,
            'created_by' => $this->usuario->id,
        ])->fresh();
    }

    public function test_se_consulta_una_guia_sin_iniciar_sesion(): void
    {
        $guia = $this->guia();

        $this->get(route('rastreo.ver', $guia->code))
            ->assertOk()
            ->assertSee($guia->code)
            ->assertSee('Recibido')
            ->assertSee('San José')
            ->assertSee('Limón');
    }

    /**
     * La protección de fondo no es el límite de intentos, es que la página no
     * tenga nada que valga la pena robar.
     */
    public function test_el_portal_no_expone_datos_personales_ni_montos(): void
    {
        $guia = $this->guia();

        $respuesta = $this->get(route('rastreo.ver', $guia->code))->assertOk();

        foreach ([
            '7011-2233',            // teléfono del receptor
            '8811-2233',            // teléfono del remitente
            'jose@cliente.test',    // correo
            '112340567',            // cédula
            'Marta Solano',         // remitente completo
            '50,850',               // total
            '45,000',               // subtotal
        ] as $dato) {
            $respuesta->assertDontSee($dato);
        }
    }

    /** Se confirma al destinatario sin exponerlo: «José Fernández» → «José F.» */
    public function test_el_nombre_del_destinatario_va_enmascarado(): void
    {
        $guia = $this->guia();

        $this->get(route('rastreo.ver', $guia->code))
            ->assertOk()
            ->assertSee('José F.')
            ->assertDontSee('José Fernández');
    }

    public function test_un_codigo_inexistente_no_revienta(): void
    {
        $this->get(route('rastreo.ver', 'SJ-LIM-99999'))
            ->assertOk()
            ->assertSee('No encontramos ninguna encomienda');
    }

    public function test_el_recorrido_muestra_la_linea_de_tiempo(): void
    {
        Bus::fake();
        $guia = $this->guia();
        $estados = app(GuideStatusService::class);

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $estados->cambiar($guia, $estado, $this->usuario, $this->sj);
        }

        $this->get(route('rastreo.ver', $guia->code))
            ->assertOk()
            ->assertSee('Listo para envío')
            ->assertSee('Enviado')
            ->assertSee('Llegó al destino');
    }

    /** Quien tiene el paquete sin retirar necesita ver hasta cuándo. */
    public function test_avisa_cuando_la_encomienda_esta_por_desecharse(): void
    {
        Bus::fake();
        $guia = $this->guia();
        $estados = app(GuideStatusService::class);

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION, Invoice::STATUS_NEAR_DISPOSAL] as $estado) {
            $guia = $estados->cambiar($guia, $estado, $this->usuario, $this->lim);
        }

        $this->get(route('rastreo.ver', $guia->code))
            ->assertOk()
            ->assertSee('próxima a desecho')
            ->assertSee('Debe retirarse antes del');
    }

    public function test_el_buscador_redirige_al_detalle(): void
    {
        $guia = $this->guia();

        $this->get(route('rastreo.buscar', ['codigo' => $guia->code]))
            ->assertRedirect(route('rastreo.ver', $guia->code));
    }

    public function test_el_buscador_vacio_muestra_el_formulario(): void
    {
        $this->get(route('rastreo.buscar'))
            ->assertOk()
            ->assertSee('Consultar una encomienda');
    }

    /**
     * El portal no debe respetar el filtro por sede: quien consulta no tiene
     * sesión, y una guía de Limón se rastrea igual desde cualquier lado.
     */
    public function test_se_consulta_cualquier_guia_sin_importar_la_sede(): void
    {
        $guia = Invoice::withoutGlobalScopes()->create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->lim->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'R', 'recipient_name' => 'D',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->usuario->id,
        ])->fresh();

        $this->get(route('rastreo.ver', $guia->code))->assertOk()->assertSee($guia->code);
    }

    public function test_la_pagina_pide_no_ser_indexada(): void
    {
        $guia = $this->guia();

        $this->get(route('rastreo.ver', $guia->code))
            ->assertOk()
            ->assertSee('noindex', false);
    }
}
