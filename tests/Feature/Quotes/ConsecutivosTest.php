<?php

namespace Tests\Feature\Quotes;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditStatement;
use App\Models\ElectronicBillingSequence;
use App\Models\Invoice;
use App\Models\PrintLog;
use App\Models\Quote;
use App\Models\User;
use App\Services\Hacienda\ClaveGenerator;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consecutivos que no sean las guías ni los cierres (esos tienen su propia
 * prueba): cotizaciones, estados de cuenta, Hacienda y copias del recibo.
 */
class ConsecutivosTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        $this->lim = Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function cotizacion(string $codigo, Branch $origen, Branch $destino, User $creador): Quote
    {
        return Quote::create([
            'code' => $codigo, 'origin_branch_id' => $origen->id, 'destination_branch_id' => $destino->id,
            'customer_name' => 'Cliente', 'subtotal' => 0, 'tax_total' => 0, 'total' => 0,
            'created_by' => $creador->id,
        ]);
    }

    // ── Cotizaciones ──────────────────────────────────────────────────

    /** Antes seguía la numeración de todas las empresas juntas. */
    public function test_cada_empresa_numera_sus_cotizaciones_desde_uno(): void
    {
        $this->cotizacion('COT-000057', $this->sj, $this->lim, $this->admin);

        $otra = Company::create(['name' => 'Transportes Vecinos', 'slug' => 'vecinos', 'is_active' => true]);

        $codigo = CompanyContext::para($otra, fn () => Quote::siguienteCodigo());

        $this->assertSame('COT-000001', $codigo);
        $this->assertSame('COT-000058', Quote::siguienteCodigo());
    }

    /** Como en los cierres: el cajero no ve las cotizaciones de otras sedes. */
    public function test_un_cajero_no_repite_la_cotizacion_de_otra_sede(): void
    {
        $her = Branch::create(['name'=>'Heredia','prefix'=>'HER','sucursal_code'=>'003','terminal_code'=>'00001','is_active'=>true]);
        $this->cotizacion('COT-000001', $her, $this->lim, $this->admin);

        $cajero = User::create(['name'=>'Ana','username'=>'ana','email'=>'ana@t.test','branch_id'=>$this->sj->id,
            'password'=>bcrypt('x'),'role'=>User::ROLE_CAJERO,'is_active'=>true]);

        $this->actingAs($cajero);

        $this->assertSame('COT-000002', Quote::siguienteCodigo());
    }

    public function test_una_cotizacion_con_un_codigo_ya_usado_se_salta(): void
    {
        $this->cotizacion('COT-000001', $this->sj, $this->lim, $this->admin);
        $this->cotizacion('COT-000003', $this->sj, $this->lim, $this->admin);
        // La última por id quedó con un número más bajo que otra.
        $this->cotizacion('COT-000002', $this->sj, $this->lim, $this->admin);

        $this->assertSame('COT-000004', Quote::siguienteCodigo());
    }

    // ── Estados de cuenta ─────────────────────────────────────────────

    public function test_un_estado_de_cuenta_con_un_codigo_ya_usado_se_salta(): void
    {
        $cliente = \App\Models\Customer::create([
            'name' => 'Ferretería', 'identification_type' => '02', 'identification' => '3101000001',
        ]);

        CreditStatement::create([
            'code' => 'EC-000001', 'customer_id' => $cliente->id,
            'period_start' => now()->toDateString(), 'period_end' => now()->toDateString(),
            'due_date' => now()->toDateString(), 'total' => 0, 'paid' => 0, 'balance' => 0,
            'status' => CreditStatement::STATUS_ISSUED, 'issued_by' => $this->admin->id, 'issued_at' => now(),
        ]);
        DB::table('credit_statements')->update(['code' => 'EC-000002']);

        $metodo = new \ReflectionMethod(\App\Services\CreditoService::class, 'siguienteCodigo');

        $this->assertSame('EC-000003', $metodo->invoke(app(\App\Services\CreditoService::class)));
    }

    // ── Hacienda ──────────────────────────────────────────────────────

    /**
     * Si otro proceso creó el contador de la sede entre la consulta y la
     * creación, se usa el suyo en vez de tumbar el comprobante.
     */
    public function test_el_consecutivo_de_hacienda_usa_el_contador_que_ya_existe(): void
    {
        ElectronicBillingSequence::create(['branch_id' => $this->sj->id, 'document_type' => '04', 'last_number' => 7]);

        $consecutivo = app(ClaveGenerator::class)->allocateConsecutivo($this->sj, '04');

        $this->assertSame('00100001040000000008', $consecutivo);
    }

    // ── Copias del recibo ─────────────────────────────────────────────

    public function test_las_copias_del_recibo_se_numeran_una_tras_otra(): void
    {
        $guia = Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id, 'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 0, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 0,
            'created_by' => $this->admin->id,
        ]);

        foreach (range(1, 3) as $_) {
            $this->actingAs($this->admin)->get(route('invoices.recibo', $guia))->assertOk();
        }

        $this->assertSame([1, 2, 3], PrintLog::orderBy('id')->pluck('copy_number')->all());
    }
}
