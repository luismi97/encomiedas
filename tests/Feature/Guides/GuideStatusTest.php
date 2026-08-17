<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use RuntimeException;
use Tests\TestCase;

class GuideStatusTest extends TestCase
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
            'name' => 'Cajero', 'username' => 'cajero', 'email' => 'cajero@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 130, 'total' => 1130,
            'created_by' => $this->usuario->id,
        ])->fresh();
    }

    private function servicio(): GuideStatusService
    {
        return app(GuideStatusService::class);
    }

    /** Recorrido completo del ciclo pedido en el requisito. */
    public function test_el_ciclo_completo_de_ocho_estados(): void
    {
        Bus::fake();
        $guia = $this->guia();

        $recorrido = [
            Invoice::STATUS_READY,
            Invoice::STATUS_DISPATCHED,
            Invoice::STATUS_IN_TRANSIT,
            Invoice::STATUS_AT_DESTINATION,
            Invoice::STATUS_DELIVERED,
        ];

        foreach ($recorrido as $estado) {
            $guia = $this->servicio()->cambiar($guia, $estado, $this->usuario);
            $this->assertSame($estado, $guia->status);
        }

        // Creación + cinco cambios.
        $this->assertSame(6, $guia->statusHistories()->count());
    }

    public function test_una_transicion_no_permitida_se_rechaza_con_su_motivo(): void
    {
        $guia = $this->guia();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('solo puede ir a');

        // De "Recibido" no se salta a "Entregado" sin pasar por el camino.
        $this->servicio()->cambiar($guia, Invoice::STATUS_DELIVERED, $this->usuario);
    }

    public function test_una_guia_en_estado_final_no_admite_mas_cambios(): void
    {
        Bus::fake();
        $guia = $this->guia();
        $guia->forceFill(['status' => Invoice::STATUS_DELIVERED])->save();

        $this->expectExceptionMessage('estado final');

        $this->servicio()->cambiar($guia->fresh(), Invoice::STATUS_IN_TRANSIT, $this->usuario);
    }

    public function test_cada_cambio_deja_quien_cuando_y_desde_donde(): void
    {
        $guia = $this->servicio()->cambiar($this->guia(), Invoice::STATUS_READY, $this->usuario, $this->sj, GuideStatusHistory::SOURCE_SCAN, 'Escaneado en bodega');

        $ultimo = $guia->statusHistories()->latest('id')->first();

        $this->assertSame(Invoice::STATUS_PENDING, $ultimo->from_status);
        $this->assertSame(Invoice::STATUS_READY, $ultimo->to_status);
        $this->assertSame($this->usuario->id, $ultimo->user_id);
        $this->assertSame($this->sj->id, $ultimo->branch_id);
        $this->assertSame('Escaneo', $ultimo->sourceLabel());
        $this->assertSame('Escaneado en bodega', $ultimo->note);
        $this->assertNotNull($ultimo->happened_at);
    }

    /** La bitácora sostiene la trazabilidad: no se actualiza, solo crece. */
    public function test_la_bitacora_no_tiene_updated_at(): void
    {
        $guia = $this->servicio()->cambiar($this->guia(), Invoice::STATUS_READY, $this->usuario);

        $fila = $guia->statusHistories()->latest('id')->first();

        $this->assertNull(GuideStatusHistory::UPDATED_AT);
        $this->assertArrayNotHasKey('updated_at', $fila->getAttributes());
    }

    public function test_al_crear_la_guia_ya_queda_la_primera_fila(): void
    {
        $guia = $this->guia();

        $primera = $guia->statusHistories()->first();

        $this->assertNull($primera->from_status);
        $this->assertSame(Invoice::STATUS_PENDING, $primera->to_status);
        $this->assertSame($this->sj->id, $primera->branch_id);
    }

    public function test_llegar_al_destino_sella_la_fecha_que_usa_el_desecho(): void
    {
        Bus::fake();
        $guia = $this->guia();

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $this->servicio()->cambiar($guia, $estado, $this->usuario);
        }

        $this->assertNotNull($guia->arrived_at);
    }

    public function test_entregar_sigue_disparando_la_facturacion_electronica(): void
    {
        Bus::fake();
        $guia = $this->guia();

        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $estado) {
            $guia = $this->servicio()->cambiar($guia, $estado, $this->usuario);
        }

        // Sin configuración de Hacienda no se encola nada, pero el observer
        // tiene que seguir corriendo sobre 'delivered': ese valor no cambió.
        $guia = $this->servicio()->cambiar($guia, Invoice::STATUS_DELIVERED, $this->usuario);

        $this->assertSame(Invoice::STATUS_DELIVERED, $guia->status);
        $this->assertNotNull($guia->delivered_at);
    }

    public function test_los_siguientes_estados_se_ofrecen_segun_el_actual(): void
    {
        $guia = $this->guia();

        $this->assertSame(
            ['ready' => 'Listo para envío', 'cancelled' => 'Anulado'],
            $guia->siguientesEstados()
        );

        $guia = $this->servicio()->cambiar($guia, Invoice::STATUS_READY, $this->usuario);

        $this->assertArrayHasKey(Invoice::STATUS_DISPATCHED, $guia->siguientesEstados());
    }

    /**
     * La columna status es VARCHAR(20) en MySQL. Un estado más largo se
     * truncaría allá y aquí no, porque SQLite no impone el ancho: los tests
     * pasarían en verde y reventaría en producción, que es justo lo que pasó
     * cuando la columna todavía era un ENUM.
     */
    public function test_ningun_estado_excede_el_ancho_de_la_columna(): void
    {
        foreach (array_keys(Invoice::STATUSES) as $estado) {
            $this->assertLessThanOrEqual(
                20,
                strlen($estado),
                "El estado «{$estado}» no cabe en la columna: hay que ampliarla con una migración."
            );
        }
    }

    /** Cada estado del ciclo tiene etiqueta, color y badge: nada a medias. */
    public function test_todos_los_estados_estan_completos(): void
    {
        foreach (array_keys(Invoice::STATUSES) as $estado) {
            $this->assertArrayHasKey($estado, Invoice::STATUS_COLORS, "Falta el color de «{$estado}».");
            $this->assertArrayHasKey($estado, Invoice::STATUS_BADGE_CLASSES, "Falta el badge de «{$estado}».");
            $this->assertArrayHasKey($estado, Invoice::TRANSITIONS, "Falta la transición de «{$estado}».");
        }
    }

    public function test_cambiar_al_mismo_estado_no_ensucia_la_bitacora(): void
    {
        $guia = $this->guia();
        $antes = $guia->statusHistories()->count();

        $this->servicio()->cambiar($guia, Invoice::STATUS_PENDING, $this->usuario);

        $this->assertSame($antes, $guia->fresh()->statusHistories()->count());
    }
}
