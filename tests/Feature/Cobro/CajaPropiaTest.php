<?php

namespace Tests\Feature\Cobro;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\CajaService;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use RuntimeException;
use Tests\TestCase;

/**
 * El cobro entra al turno de quien cobra, no al de un compañero.
 *
 * Bastaba con que CUALQUIERA tuviera la caja abierta en la sede: un cajero sin
 * turno propio vendía igual y su dinero caía en el arqueo del compañero, que
 * terminaba respondiendo por un faltante de plata que nunca vio.
 */
class CajaPropiaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $ana;
    private User $beto;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->sj->cashRegisters()->create(['name'=>'Mostrador 2','is_active'=>true]);

        $this->ana  = $this->cajero('Ana Campos', 'ana', $this->sj);
        $this->beto = $this->cajero('Beto Rojas', 'beto', $this->sj);
    }

    private function cajero(string $nombre, string $usuario, Branch $sede): User
    {
        return User::create(['name'=>$nombre,'username'=>$usuario,'email'=>$usuario.'@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_CAJERO,'is_active'=>true,'branch_id'=>$sede->id]);
    }

    private function abrirPara(User $quien, string $caja = 'Caja principal')
    {
        return app(CajaService::class)->abrir(
            $this->sj->cashRegisters()->where('name', $caja)->firstOrFail(), $quien, 10000
        );
    }

    private function formularioDe(User $quien)
    {
        return Livewire::actingAs($quien)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000);
    }

    // ── Lo reportado ──────────────────────────────────────────────────

    /** Ana tiene turno; Beto no. Beto no puede vender de contado. */
    public function test_no_se_vende_apoyandose_en_la_caja_de_un_companero(): void
    {
        $this->abrirPara($this->ana);

        $this->formularioDe($this->beto)
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertHasErrors('cobro')
            ->assertSee('No tenés una caja abierta');

        $this->assertSame(0, Invoice::count());
    }

    public function test_con_su_propia_caja_beto_si_vende(): void
    {
        $this->abrirPara($this->ana);
        $suya = $this->abrirPara($this->beto, 'Mostrador 2');

        $this->formularioDe($this->beto)->set('cobro', 'prepaid')->call('save')->assertHasNoErrors();

        $this->assertSame(1, CashMovement::where('cash_session_id', $suya->id)->count());
    }

    /** Y el dinero no toca el arqueo de Ana. */
    public function test_el_dinero_no_cae_en_el_arqueo_del_companero(): void
    {
        $deAna = $this->abrirPara($this->ana);
        $this->abrirPara($this->beto, 'Mostrador 2');

        $this->formularioDe($this->beto)->set('cobro', 'prepaid')->call('save');

        $this->assertSame(0, CashMovement::where('cash_session_id', $deAna->id)->count(),
            'Ana respondería por dinero que nunca manejó.');
    }

    /** Un turno abierto en OTRA sede no habilita a cobrar acá. */
    public function test_un_turno_en_otra_sede_no_sirve(): void
    {
        $enLimon = $this->cajero('Carlos', 'carlos', $this->lim);
        app(CajaService::class)->abrir($this->lim->cashRegisters()->firstOrFail(), $enLimon, 5000);

        Livewire::actingAs($enLimon)
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.price', 10000)
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertHasErrors('cobro');
    }

    // ── La entrega de un «por cobrar», por el mismo motivo ────────────

    public function test_no_se_entrega_un_por_cobrar_con_la_caja_de_otro(): void
    {
        $enDestino = $this->cajero('Carlos', 'carlos', $this->lim);
        $otro      = $this->cajero('Diana', 'diana', $this->lim);

        // Diana tiene turno; Carlos, que entrega, no.
        app(CajaService::class)->abrir($this->lim->cashRegisters()->firstOrFail(), $otro, 5000);

        $guia = Invoice::create(['status'=>Invoice::STATUS_PENDING,'pickup_branch_id'=>$this->sj->id,
            'delivery_branch_id'=>$this->lim->id,'sender_name'=>'M','recipient_name'=>'J',
            'payment_timing'=>Invoice::TIMING_COLLECT,
            'subtotal'=>8000,'discount_amount'=>0,'tax_total'=>0,'total'=>8000,
            'created_by'=>$this->ana->id])->fresh();

        $estados = app(GuideStatusService::class);
        foreach ([Invoice::STATUS_READY, Invoice::STATUS_DISPATCHED, Invoice::STATUS_AT_DESTINATION] as $e) {
            $guia = $estados->cambiar($guia, $e, $enDestino);
        }

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no tenés una caja abierta');

        $estados->entregar($guia, $enDestino, 'José');
    }

    // ── Lo que no cambia ──────────────────────────────────────────────

    public function test_un_por_cobrar_se_registra_sin_caja_propia(): void
    {
        $this->formularioDe($this->beto)->set('cobro', 'collect')->call('save')->assertHasNoErrors();

        $this->assertSame(1, Invoice::count());
    }

    public function test_el_mensaje_habla_en_primera_persona(): void
    {
        $this->abrirPara($this->ana);

        $this->formularioDe($this->beto)
            ->set('cobro', 'prepaid')
            ->call('save')
            ->assertSee('Abrí tu caja');
    }
}
