<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerIndex;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class CustomerIndexTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@t.test'],
            ['name' => 'Admin', 'username' => 'admin', 'password' => bcrypt('x'),
             'role' => User::ROLE_ADMIN, 'is_active' => true]
        );
    }

    private function formulario()
    {
        return Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('create')
            ->set('name', 'Transportes Vargas S.A.');
    }

    public function test_se_guarda_un_cliente_de_contado_sin_cedula(): void
    {
        $this->formulario()
            ->set('phone', '8811-2233')
            ->call('save')
            ->assertHasNoErrors();

        $cliente = Customer::firstOrFail();

        $this->assertSame(Customer::PAYMENT_CASH, $cliente->payment_condition);
        $this->assertNull($cliente->identification);
        // Sin cédula el comprobante tiene que salir como tiquete.
        $this->assertFalse($cliente->puedeFacturaElectronica());
    }

    /**
     * La cédula lleva índice único. Guardar cadena vacía en vez de null haría
     * que el segundo cliente de contado sin cédula chocara contra el índice.
     */
    public function test_varios_clientes_pueden_no_tener_cedula(): void
    {
        foreach (['Marta Solano', 'Kenneth Araya', 'Ivannia Rojas'] as $nombre) {
            Livewire::actingAs($this->admin())
                ->test(CustomerIndex::class)
                ->call('create')
                ->set('name', $nombre)
                ->call('save')
                ->assertHasNoErrors();
        }

        $this->assertSame(3, Customer::count());
        $this->assertSame(3, Customer::whereNull('identification')->count());
    }

    public function test_la_cedula_se_guarda_sin_guiones(): void
    {
        $this->formulario()
            ->set('identification', '3-101-123456')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('3101123456', Customer::firstOrFail()->identification);
    }

    public function test_no_se_repite_la_misma_cedula(): void
    {
        Customer::create(['name' => 'Ya existe', 'identification' => '112340567', 'identification_type' => '01']);

        $this->formulario()
            ->set('identification', '112340567')
            ->call('save')
            ->assertHasErrors('identification')
            ->assertSee('Ya hay otro cliente registrado');
    }

    /**
     * Un cliente de crédito se factura al cierre del período, y Hacienda exige
     * receptor identificado en la Factura Electrónica.
     */
    public function test_un_cliente_de_credito_exige_cedula(): void
    {
        $this->formulario()
            ->set('payment_condition', Customer::PAYMENT_CREDIT)
            ->set('credit_limit', 500000)
            ->call('save')
            ->assertHasErrors('identification')
            ->assertSee('no se le puede');

        $this->assertSame(0, Customer::count());
    }

    public function test_un_cliente_de_credito_guarda_limite_y_dia_de_corte(): void
    {
        $this->formulario()
            ->set('identification', '3101999888')
            ->set('identification_type', '02')
            ->set('payment_condition', Customer::PAYMENT_CREDIT)
            ->set('credit_limit', 750000)
            ->set('credit_cutoff_day', 30)
            ->call('save')
            ->assertHasNoErrors();

        $cliente = Customer::firstOrFail();

        $this->assertTrue($cliente->isCredit());
        $this->assertSame('750000.00', (string) $cliente->credit_limit);
        $this->assertSame(30, $cliente->credit_cutoff_day);
        $this->assertTrue($cliente->puedeFacturaElectronica());
    }

    /** Pasar a contado no debe dejar colgando un límite que ya no aplica. */
    public function test_volver_a_contado_limpia_los_datos_de_credito(): void
    {
        $cliente = Customer::create([
            'name' => 'Crédito', 'identification' => '3101999888', 'identification_type' => '02',
            'payment_condition' => Customer::PAYMENT_CREDIT, 'credit_limit' => 500000, 'credit_cutoff_day' => 15,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('edit', $cliente->id)
            ->set('payment_condition', Customer::PAYMENT_CASH)
            ->call('save')
            ->assertHasNoErrors();

        $cliente->refresh();
        $this->assertSame('0.00', (string) $cliente->credit_limit);
        $this->assertNull($cliente->credit_cutoff_day);
    }

    public function test_el_dia_de_corte_no_pasa_de_31(): void
    {
        $this->formulario()
            ->set('identification', '3101999888')
            ->set('payment_condition', Customer::PAYMENT_CREDIT)
            ->set('credit_cutoff_day', 45)
            ->call('save')
            ->assertHasErrors('credit_cutoff_day');
    }

    public function test_se_busca_por_nombre_y_por_cedula(): void
    {
        Customer::create(['name' => 'Marta Solano', 'identification' => '112340567', 'identification_type' => '01']);
        Customer::create(['name' => 'Kenneth Araya', 'identification' => '203450678', 'identification_type' => '01']);

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->set('search', 'Marta')
            ->assertSee('Marta Solano')
            ->assertDontSee('Kenneth Araya')
            ->set('search', '203450678')
            ->assertSee('Kenneth Araya')
            ->assertDontSee('Marta Solano');
    }

    public function test_se_filtra_por_condicion_de_pago(): void
    {
        Customer::create(['name' => 'De contado', 'payment_condition' => Customer::PAYMENT_CASH]);
        Customer::create(['name' => 'De crédito', 'identification' => '3101999888',
                          'identification_type' => '02', 'payment_condition' => Customer::PAYMENT_CREDIT]);

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->set('filterCondition', Customer::PAYMENT_CREDIT)
            ->assertSee('De crédito')
            ->assertDontSee('De contado');
    }

    public function test_se_desactiva_un_cliente_sin_borrarlo(): void
    {
        $cliente = Customer::create(['name' => 'Inactivo pronto']);

        Livewire::actingAs($this->admin())
            ->test(CustomerIndex::class)
            ->call('toggleActive', $cliente->id)
            ->assertSet('feedbackType', 'success');

        $this->assertFalse($cliente->fresh()->is_active);
        $this->assertDatabaseHas('customers', ['id' => $cliente->id]);
    }
}
