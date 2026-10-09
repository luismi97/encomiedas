<?php

namespace Tests\Feature\Caja;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Invoice;
use App\Models\PackageType;
use App\Models\User;
use App\Services\CajaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Lo que el arqueo espera tiene que ser lo que de verdad entró a la gaveta.
 */
class CuadreDelTurnoTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $cajera;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->cajera = User::create(['name' => 'Ana', 'username' => 'ana', 'email' => 'ana@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_CAJERO, 'is_active' => true, 'branch_id' => $this->sj->id]);
    }

    private function abrirTurno()
    {
        return app(CajaService::class)->abrir($this->sj->cashRegisters()->firstOrFail(), $this->cajera, 0);
    }

    private function guiaDeContado(): Invoice
    {
        Livewire::actingAs($this->cajera)
            ->test(InvoiceForm::class)
            ->set('wantsInvoice', false)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id)
            ->set('sender_name', 'Marta')
            ->set('recipient_name', 'José')
            ->set('items.0.package_type_id', PackageType::active()->firstOrFail()->id)
            ->set('items.0.price', 5000)
            ->set('selectedTaxes', [])
            ->set('cobro', 'prepaid')
            ->set('payment_method', 'cash')
            ->call('save')
            ->assertHasNoErrors();

        return Invoice::latest('id')->firstOrFail();
    }

    /** Editar la guía otro día no la vuelve a cobrar en el turno nuevo. */
    public function test_editar_una_guia_cobrada_en_otro_turno_no_la_cobra_otra_vez(): void
    {
        $primero = $this->abrirTurno();
        $guia = $this->guiaDeContado();
        app(CajaService::class)->cerrar($primero, $this->cajera, []);

        $segundo = $this->abrirTurno();

        Livewire::actingAs($this->cajera)
            ->test(InvoiceForm::class, ['invoice' => $guia])
            ->set('notes', 'Llamar antes de entregar')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(1, CashMovement::where('invoice_id', $guia->id)->count(), 'La guía se cobró dos veces.');
        $this->assertEquals(0, app(CajaService::class)->efectivoEsperado($segundo->fresh()));
    }

    /** El PDF muestra de dónde sale el esperado, para rehacer la cuenta a mano. */
    public function test_el_pdf_del_cierre_desglosa_el_esperado(): void
    {
        $turno = $this->abrirTurno();
        $turno->forceFill(['opening_float' => 50000])->save();
        $this->guiaDeContado();
        $caja = app(CajaService::class);
        $caja->registrarMovimiento($turno->fresh(), CashMovement::TYPE_OUT, 1000, 'compra cinta', $this->cajera);
        $caja->registrarMovimiento($turno->fresh(), CashMovement::TYPE_IN, 25000, 'para vueltos', $this->cajera);
        $caja->cerrar($turno->fresh(), $this->cajera, []);

        $sesion = $turno->fresh()->load(['movements.invoice', 'counts.denomination', 'opener', 'closer', 'register.branch', 'branch']);
        $html = view('pdf.cash-session', [
            'sesion' => $sesion, 'porMedio' => $caja->totalesPorMedio($sesion),
            'company' => \App\Models\CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('+ Cobros en efectivo</td><td>₡5,000.00', $html);
        $this->assertStringContainsString('+ Entradas</td><td>₡25,000.00', $html);
        $this->assertStringContainsString('− Salidas</td><td>−₡1,000.00', $html);
        // 50.000 + 5.000 + 25.000 − 1.000
        $this->assertStringContainsString('Efectivo esperado</td><td>₡79,000.00', $html);
    }

    /** Abierto, el esperado se calcula; no se muestra un «₡0.00 cuadrado» que no es cierto. */
    public function test_el_pdf_de_un_turno_abierto_no_dice_que_cuadra(): void
    {
        $turno = $this->abrirTurno();
        $this->guiaDeContado();

        $sesion = $turno->fresh()->load(['movements.invoice', 'counts.denomination', 'opener', 'closer', 'register.branch', 'branch']);
        $html = view('pdf.cash-session', [
            'sesion' => $sesion, 'porMedio' => app(CajaService::class)->totalesPorMedio($sesion),
            'company' => \App\Models\CompanySetting::instance(),
        ])->render();

        $this->assertStringContainsString('Efectivo esperado a hoy</td><td>₡5,000.00', $html);
        $this->assertStringContainsString('todavía no tiene arqueo', $html);
        $this->assertStringNotContainsString('Efectivo contado', $html);
    }
}
