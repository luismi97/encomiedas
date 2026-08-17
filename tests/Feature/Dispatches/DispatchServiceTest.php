<?php

namespace Tests\Feature\Dispatches;

use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DispatchServiceTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private Branch $her;
    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        $this->her = Branch::create(['name' => 'Heredia', 'prefix' => 'HER', 'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true]);

        $this->usuario = User::create([
            'name' => 'Bodeguero', 'username' => 'bodega', 'email' => 'bodega@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function servicio(): DispatchService
    {
        return app(DispatchService::class);
    }

    private function guia(?Branch $origen = null, ?Branch $destino = null): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => ($origen ?? $this->sj)->id,
            'delivery_branch_id' => ($destino ?? $this->lim)->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'declared_value' => 20000,
            'created_by' => $this->usuario->id,
        ])->fresh();
    }

    private function manifiesto(): Dispatch
    {
        return Dispatch::create([
            'code' => 'CIE-000001',
            'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id,
            'driver_name' => 'Randall Mora',
            'vehicle_plate' => 'CL-123456',
            'created_by' => $this->usuario->id,
        ]);
    }

    public function test_se_arma_un_manifiesto_con_guias_de_su_ruta(): void
    {
        $manifiesto = $this->manifiesto();
        $guia = $this->guia();

        $this->servicio()->agregarGuia($manifiesto, $guia);

        $this->assertSame(1, $manifiesto->fresh()->guides()->count());
    }

    /** Un manifiesto es de una ruta: mezclar destinos es cómo se pierde carga. */
    public function test_no_admite_guias_de_otra_ruta(): void
    {
        $manifiesto = $this->manifiesto();
        $otraRuta = $this->guia($this->sj, $this->her);

        $this->expectExceptionMessage('es de otra ruta');

        $this->servicio()->agregarGuia($manifiesto, $otraRuta);
    }

    public function test_una_guia_no_puede_ir_en_dos_manifiestos_a_la_vez(): void
    {
        $primero = $this->manifiesto();
        $guia = $this->guia();
        $this->servicio()->agregarGuia($primero, $guia);

        $segundo = Dispatch::create([
            'code' => 'CIE-000002',
            'origin_branch_id' => $this->sj->id,
            'destination_branch_id' => $this->lim->id,
            'created_by' => $this->usuario->id,
        ]);

        $disponibles = $this->servicio()->disponiblesPara($segundo);

        $this->assertFalse($disponibles->contains('id', $guia->id));
    }

    public function test_despachar_pone_todas_las_guias_en_enviado(): void
    {
        $manifiesto = $this->manifiesto();
        $guias = collect([$this->guia(), $this->guia(), $this->guia()]);
        $guias->each(fn ($g) => $this->servicio()->agregarGuia($manifiesto, $g));

        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $this->assertSame(Dispatch::STATUS_DISPATCHED, $manifiesto->status);
        $this->assertNotNull($manifiesto->departed_at);

        foreach ($guias as $guia) {
            $this->assertSame(Invoice::STATUS_DISPATCHED, $guia->fresh()->status);
        }
    }

    /** El paso por "Listo para envío" queda en la bitácora, no se salta. */
    public function test_el_despacho_deja_rastro_en_la_bitacora_de_cada_guia(): void
    {
        $manifiesto = $this->manifiesto();
        $guia = $this->guia();
        $this->servicio()->agregarGuia($manifiesto, $guia);

        $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $pasos = $guia->fresh()->statusHistories;

        $this->assertSame(Invoice::STATUS_READY, $pasos[1]->to_status);
        $this->assertSame(Invoice::STATUS_DISPATCHED, $pasos[2]->to_status);
        $this->assertStringContainsString('CIE-000001', $pasos[2]->note);
    }

    public function test_no_se_despacha_un_manifiesto_vacio(): void
    {
        $this->expectExceptionMessage('no tiene guías');

        $this->servicio()->despachar($this->manifiesto(), $this->usuario);
    }

    public function test_un_manifiesto_despachado_ya_no_admite_cambios(): void
    {
        $manifiesto = $this->manifiesto();
        $guia = $this->guia();
        $this->servicio()->agregarGuia($manifiesto, $guia);
        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $this->expectException(RuntimeException::class);

        $this->servicio()->agregarGuia($manifiesto, $this->guia());
    }

    // ── Recepción en destino y control de faltantes ───────────────────

    public function test_recibir_una_guia_la_pone_en_destino(): void
    {
        $manifiesto = $this->manifiesto();
        $guia = $this->guia();
        $this->servicio()->agregarGuia($manifiesto, $guia);
        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $this->servicio()->recibirGuia($manifiesto, $guia->fresh(), $this->usuario);

        $guia->refresh();
        $this->assertSame(Invoice::STATUS_AT_DESTINATION, $guia->status);
        $this->assertNotNull($guia->arrived_at);
        $this->assertNotNull($manifiesto->fresh()->lines->first()->received_at);
    }

    /**
     * El corazón del módulo: la diferencia entre lo despachado y lo recibido.
     */
    public function test_lo_que_no_se_marca_queda_como_faltante(): void
    {
        $manifiesto = $this->manifiesto();
        $llegan = [$this->guia(), $this->guia()];
        $seExtravia = $this->guia();

        foreach ([...$llegan, $seExtravia] as $g) {
            $this->servicio()->agregarGuia($manifiesto, $g);
        }

        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        foreach ($llegan as $g) {
            $this->servicio()->recibirGuia($manifiesto, $g->fresh(), $this->usuario);
        }

        $resumen = $this->servicio()->cerrarRecepcion($manifiesto->fresh(), $this->usuario);

        $this->assertSame(2, $resumen['recibidas']);
        $this->assertSame([$seExtravia->fresh()->code], $resumen['faltantes']);

        // La que no llegó sigue en "Enviado", no se da por recibida.
        $this->assertSame(Invoice::STATUS_DISPATCHED, $seExtravia->fresh()->status);
        $this->assertSame('faltante', $manifiesto->fresh()->lines()
            ->where('invoice_id', $seExtravia->id)->first()->incident);
    }

    public function test_una_guia_ajena_al_cierre_no_se_puede_recibir(): void
    {
        $manifiesto = $this->manifiesto();
        $this->servicio()->agregarGuia($manifiesto, $this->guia());
        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $ajena = $this->guia();

        $this->expectExceptionMessage('no viene en este cierre');

        $this->servicio()->recibirGuia($manifiesto, $ajena, $this->usuario);
    }

    public function test_recibir_dos_veces_la_misma_guia_no_duplica_nada(): void
    {
        $manifiesto = $this->manifiesto();
        $guia = $this->guia();
        $this->servicio()->agregarGuia($manifiesto, $guia);
        $manifiesto = $this->servicio()->despachar($manifiesto->fresh(), $this->usuario);

        $this->servicio()->recibirGuia($manifiesto, $guia->fresh(), $this->usuario);
        $pasos = $guia->fresh()->statusHistories()->count();

        $this->servicio()->recibirGuia($manifiesto, $guia->fresh(), $this->usuario);

        $this->assertSame($pasos, $guia->fresh()->statusHistories()->count());
    }

    public function test_los_totales_del_manifiesto_cuadran(): void
    {
        $manifiesto = $this->manifiesto();

        foreach ([$this->guia(), $this->guia()] as $guia) {
            $guia->items()->create(['package_code' => 'PKG', 'weight' => 2.5, 'price' => 1000]);
            $this->servicio()->agregarGuia($manifiesto, $guia);
        }

        $manifiesto = $manifiesto->fresh()->load('guides.items');

        $this->assertSame(2, $manifiesto->totalPaquetes());
        $this->assertSame(5.0, $manifiesto->pesoTotal());
        $this->assertSame(40000.0, $manifiesto->valorDeclaradoTotal());
    }
}
