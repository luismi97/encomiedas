<?php

namespace Tests\Feature\Ui;

use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Invoices\InvoiceIndex;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Los listados con la tabla grande.
 *
 * Tres mil guías diarias son más de un millón de filas al año. Lo que se prueba
 * acá no es que se vean bonitas, sino las dos decisiones que las sostienen: que
 * nunca se cuente la tabla entera para pintar un pie de página, y que el
 * buscador no dependa de un comodín inicial que ningún índice puede aprovechar.
 */
class ScrollYBusquedaTest extends TestCase
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

    private function guia(string $remitente = 'Marta', string $destinatario = 'José'): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => $remitente, 'recipient_name' => $destinatario,
            'subtotal' => 1000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 1000,
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    private function listado()
    {
        return Livewire::actingAs($this->admin)->test(InvoiceIndex::class);
    }

    // ── Scroll ────────────────────────────────────────────────────────

    /**
     * El pie de página costaba un `count(*)` sobre el conjunto filtrado, en cada
     * tecla del buscador. Con un millón de filas ese conteo era la consulta cara.
     */
    public function test_el_listado_no_cuenta_la_tabla_para_pintarse(): void
    {
        foreach (range(1, 5) as $i) {
            $this->guia();
        }

        $consultas = [];
        DB::listen(function ($q) use (&$consultas) {
            $consultas[] = $q->sql;
        });

        $this->listado()->assertOk();

        $conteos = array_filter($consultas, fn (string $sql) => str_contains(strtolower($sql), 'count('));

        $this->assertSame([], array_values($conteos),
            'El listado volvió a contar filas para armarse: ' . implode(' | ', $conteos));
    }

    /** Pide una fila de más: si vuelve, hay más abajo. Nunca cuenta. */
    public function test_ofrece_cargar_mas_solo_cuando_hay_mas(): void
    {
        $this->guia();

        $this->listado()->assertDontSee('Cargar más');
    }

    public function test_cargar_mas_agranda_la_tanda_sin_perder_lo_de_arriba(): void
    {
        foreach (range(1, 3) as $i) {
            $this->guia("Remitente {$i}");
        }

        $componente = $this->listado();
        // Una tanda diminuta para no crear cincuenta guías en la prueba.
        $componente->set('visibles', 2)
            ->assertSee('Cargar más')
            ->call('cargarMas');

        // La tanda crece de a 50, así que con tres guías ya no queda nada debajo.
        $componente->assertDontSee('Cargar más')
            ->assertSee('Remitente 1')
            ->assertSee('Remitente 3');
    }

    /** Filtrar con 500 filas en pantalla pediría 500 filas del filtro nuevo. */
    public function test_cambiar_un_filtro_vuelve_a_la_primera_tanda(): void
    {
        $this->guia();

        $this->listado()
            ->set('visibles', 300)
            ->set('search', 'Marta')
            ->assertSet('visibles', 50);
    }

    // ── Buscador ──────────────────────────────────────────────────────

    public function test_el_codigo_se_busca_por_el_principio(): void
    {
        $guia = $this->guia();

        $this->listado()
            ->set('search', substr($guia->code, 0, 6))
            ->assertSee($guia->code);
    }

    /** Como lo dicta el cliente por teléfono: «la cero cero cinco». */
    public function test_el_codigo_tambien_se_busca_por_la_cola(): void
    {
        $guia = $this->guia();
        $cola = substr($guia->code, -4);

        $this->listado()
            ->set('search', $cola)
            ->assertSee($guia->code);
    }

    public function test_se_busca_por_remitente_y_por_destinatario(): void
    {
        $deMarta = $this->guia('Marta Solano', 'José Fallas');
        $deKenneth = $this->guia('Kenneth Araya', 'Ana Mora');

        $this->listado()
            ->set('search', 'Marta')
            ->assertSee($deMarta->code)
            ->assertDontSee($deKenneth->code);

        $this->listado()
            ->set('search', 'Mora')
            ->assertSee($deKenneth->code)
            ->assertDontSee($deMarta->code);
    }

    /** El apellido está a mitad del nombre: es como busca todo el mundo. */
    public function test_se_encuentra_por_una_palabra_del_medio(): void
    {
        $guia = $this->guia('Marta Solano Vargas');

        $this->listado()
            ->set('search', 'Solano')
            ->assertSee($guia->code);
    }

    /** Una sola letra traería media tabla y no es una búsqueda. */
    public function test_con_una_sola_letra_no_se_filtra_nada(): void
    {
        $marta = $this->guia('Marta');
        $kenneth = $this->guia('Kenneth');

        $this->listado()
            ->set('search', 'M')
            ->assertSee($marta->code)
            ->assertSee($kenneth->code);
    }

    /** Buscar un nombre no puede exigir además que coincida la cédula. */
    public function test_el_buscador_de_clientes_suma_condiciones_con_o(): void
    {
        Customer::create(['name' => 'Marta Solano', 'identification' => '112340567', 'identification_type' => '01']);
        Customer::create(['name' => 'Kenneth Araya', 'identification' => '203450678', 'identification_type' => '01']);

        Livewire::actingAs($this->admin)
            ->test(CustomerIndex::class)
            ->set('search', 'Marta')
            ->assertSee('Marta Solano')
            ->assertDontSee('Kenneth Araya')
            ->set('search', '203450678')
            ->assertSee('Kenneth Araya')
            ->assertDontSee('Marta Solano');
    }
}
