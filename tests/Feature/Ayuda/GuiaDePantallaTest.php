<?php

namespace Tests\Feature\Ayuda;

use App\Livewire\Ayuda\GuiaDePantalla;
use App\Models\Branch;
use App\Models\User;
use App\Support\GuiasDePantalla;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * El recorrido guiado y, sobre todo, que se acuerde de lo que el usuario decidió.
 *
 * Una ayuda que reaparece después de que la cerraste deja de ser ayuda. Y como
 * el mostrador se atiende desde varias computadoras, la decisión tiene que
 * viajar con la persona y no con el navegador.
 */
class GuiaDePantallaTest extends TestCase
{
    use RefreshDatabase;

    private User $cajero;

    protected function setUp(): void
    {
        parent::setUp();

        $sj = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);

        $this->cajero = User::create(['name'=>'Cajera','username'=>'caja','email'=>'c@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$sj->id]);
    }

    private function guia(string $clave = 'invoices.create', ?User $usuario = null)
    {
        return Livewire::actingAs($usuario ?? $this->cajero)
            ->test(GuiaDePantalla::class, ['clave' => $clave]);
    }

    public function test_se_abre_sola_la_primera_vez(): void
    {
        $this->guia()
            ->assertSet('abierta', true)
            ->assertSee('Empezá por la ruta');
    }

    public function test_avanza_paso_a_paso_y_puede_volver_atras(): void
    {
        $this->guia()
            ->assertSee('Empezá por la ruta')
            ->call('siguiente')
            ->assertSee('Remitente y destinatario')
            ->call('anterior')
            ->assertSee('Empezá por la ruta');
    }

    /** Llegar al final es haberla hecho: no hay que cerrarla además. */
    public function test_terminarla_la_cierra_y_queda_registrada(): void
    {
        $pasos = count(GuiasDePantalla::para('invoices.create')['pasos']);

        $componente = $this->guia();

        for ($i = 0; $i < $pasos; $i++) {
            $componente->call('siguiente');
        }

        $componente->assertSet('abierta', false);

        $this->assertSame('completada', $this->cajero->fresh()->guide_state['invoices.create']['estado']);
    }

    public function test_no_mostrar_mas_la_cierra_y_queda_registrada(): void
    {
        $this->guia()
            ->call('descartar')
            ->assertSet('abierta', false);

        $this->assertSame('descartada', $this->cajero->fresh()->guide_state['invoices.create']['estado']);
    }

    /** Lo que se cerró no vuelve solo: si no, la ayuda deja de ser ayuda. */
    public function test_una_vez_cerrada_no_se_vuelve_a_abrir_sola(): void
    {
        $this->cajero->marcarGuia('invoices.create', 'descartada');

        $this->guia()->assertSet('abierta', false);
    }

    /** Terminarla o descartarla cierran igual: el usuario ya decidió. */
    public function test_completarla_tampoco_la_vuelve_a_abrir(): void
    {
        $this->cajero->marcarGuia('invoices.create', 'completada');

        $this->guia()->assertSet('abierta', false);
    }

    /** La decisión es de la persona, no de la computadora en la que se sentó. */
    public function test_lo_que_vio_uno_no_se_lo_ahorra_a_otro(): void
    {
        $this->cajero->marcarGuia('invoices.create', 'completada');

        $otro = User::create(['name'=>'Nuevo','username'=>'nuevo','email'=>'n@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_CAJERO,'is_active'=>true,
            'branch_id'=>$this->cajero->branch_id]);

        $this->guia(usuario: $otro)->assertSet('abierta', true);
    }

    /** Cerrarla no es perderla: el botón «Guía» la trae de vuelta. */
    public function test_se_puede_volver_a_abrir_a_pedido(): void
    {
        $this->cajero->marcarGuia('invoices.create', 'descartada');

        $this->guia()
            ->assertSet('abierta', false)
            ->dispatch('abrir-guia')
            ->assertSet('abierta', true)
            ->assertSet('paso', 0);
    }

    /** Cada pantalla se recuerda por separado. */
    public function test_cerrar_la_de_una_pantalla_no_cierra_la_de_otra(): void
    {
        $this->guia('invoices.create')->call('descartar');

        $this->guia('dispatches.index')->assertSet('abierta', true);
    }

    public function test_en_una_pantalla_sin_guia_no_pinta_nada(): void
    {
        $this->guia('una.ruta.sin.guia')
            ->assertSet('abierta', false)
            ->assertDontSee('paso 1 de');
    }

    // ── El contenido ──────────────────────────────────────────────────

    /** Un recorrido de un solo paso no es un recorrido. */
    public function test_toda_guia_tiene_titulo_y_al_menos_dos_pasos(): void
    {
        foreach (GuiasDePantalla::todas() as $clave => $guia) {
            $this->assertNotEmpty($guia['titulo'], "La guía de «{$clave}» no tiene título.");
            $this->assertGreaterThanOrEqual(2, count($guia['pasos']),
                "La guía de «{$clave}» tiene menos de dos pasos.");

            foreach ($guia['pasos'] as $i => $paso) {
                $this->assertNotEmpty($paso['titulo'] ?? '', "Paso {$i} de «{$clave}» sin título.");
                $this->assertNotEmpty($paso['texto'] ?? '', "Paso {$i} de «{$clave}» sin texto.");
            }
        }
    }

    /**
     * Una guía cuya clave no corresponde a ninguna ruta no se muestra nunca, y
     * nadie se entera: se descubre cuando alguien pregunta por una ayuda que
     * juraba haber escrito.
     */
    public function test_toda_guia_apunta_a_una_ruta_que_existe(): void
    {
        $rutas = collect(app('router')->getRoutes())
            ->map(fn ($r) => $r->getName())
            ->filter()
            ->all();

        foreach (array_keys(GuiasDePantalla::todas()) as $clave) {
            $this->assertContains($clave, $rutas,
                "La guía «{$clave}» no corresponde a ninguna ruta: no se va a mostrar nunca.");
        }
    }

    /** Las pantallas donde entra gente nueva no pueden quedarse sin guía. */
    public function test_las_pantallas_de_operacion_tienen_guia(): void
    {
        $imprescindibles = [
            'dashboard', 'invoices.index', 'invoices.create', 'invoices.show',
            'dispatches.index', 'caja.index', 'customers.index', 'chofer.index',
        ];

        foreach ($imprescindibles as $clave) {
            $this->assertNotNull(GuiasDePantalla::para($clave),
                "La pantalla «{$clave}» se quedó sin guía.");
        }
    }
}
