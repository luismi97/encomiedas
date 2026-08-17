<?php

namespace Tests\Feature\Invoices;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class InvoiceFormTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@t.test'],
            [
                'name' => 'Admin', 'username' => 'admin',
                'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]
        );
    }

    private function escenario(): array
    {
        $a = Branch::create(['name' => 'San José', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $b = Branch::create(['name' => 'Alajuela', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);
        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);

        return [$a, $b];
    }

    private function formulario(Branch $a, Branch $b)
    {
        return Livewire::actingAs($this->admin())
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $a->id)
            ->set('delivery_branch_id', $b->id)
            ->set('sender_name', 'Marta Solano')
            ->set('recipient_name', 'José Fernández');
    }

    public function test_se_guarda_una_encomienda_con_peso_tamano_y_descripcion(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', 'PKG-001')
            ->set('items.0.size', 'XL')
            ->set('items.0.weight', '12.5')
            ->set('items.0.description', 'Repuestos electrónicos')
            ->set('items.0.price', 15000)
            ->call('save')
            ->assertHasNoErrors();

        $item = Invoice::firstOrFail()->items()->firstOrFail();

        $this->assertSame('PKG-001', $item->package_code);
        $this->assertSame('XL', $item->size);
        $this->assertSame('12.50', (string) $item->weight);
        $this->assertSame('Repuestos electrónicos', $item->description);
    }

    public function test_un_paquete_sin_peso_se_guarda_igual(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', 'PKG-002')
            ->set('items.0.weight', '')
            ->set('items.0.description', '')
            ->set('items.0.price', 5000)
            ->call('save')
            ->assertHasNoErrors();

        $item = Invoice::firstOrFail()->items()->firstOrFail();

        $this->assertNull($item->weight);
        $this->assertNull($item->description);
    }

    public function test_un_peso_negativo_se_rechaza(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', 'PKG-003')
            ->set('items.0.weight', '-5')
            ->set('items.0.price', 5000)
            ->call('save')
            ->assertHasErrors('items.0.weight');
    }

    public function test_varios_paquetes_conservan_cada_uno_sus_datos(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', 'PKG-A')
            ->set('items.0.size', 'S')
            ->set('items.0.weight', '1.25')
            ->set('items.0.price', 3000)
            ->call('addItem')
            ->set('items.1.package_code', 'PKG-B')
            ->set('items.1.size', 'L')
            ->set('items.1.weight', '9')
            ->set('items.1.price', 7000)
            ->call('save')
            ->assertHasNoErrors();

        $items = Invoice::firstOrFail()->items()->orderBy('package_code')->get();

        $this->assertCount(2, $items);
        $this->assertSame('S', $items[0]->size);
        $this->assertSame('1.25', (string) $items[0]->weight);
        $this->assertSame('L', $items[1]->size);
        $this->assertSame('9.00', (string) $items[1]->weight);
    }

    /**
     * Los campos de cada paquete no mostraban errores: solo se pintaba la regla
     * del arreglo completo. Un peso inválido dejaba el botón Guardar sin efecto
     * y sin ninguna explicación en pantalla.
     */
    public function test_el_error_de_un_paquete_se_ve_en_pantalla(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', '')
            ->set('items.0.weight', '-3')
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasErrors(['items.0.package_code', 'items.0.weight'])
            ->assertSee('El código del paquete es obligatorio.')
            ->assertSee('El peso no puede ser negativo.');
    }

    public function test_al_editar_no_se_pierden_los_datos_del_paquete(): void
    {
        [$a, $b] = $this->escenario();

        $this->formulario($a, $b)
            ->set('items.0.package_code', 'PKG-EDIT')
            ->set('items.0.size', 'L')
            ->set('items.0.weight', '4.5')
            ->set('items.0.description', 'Libros')
            ->set('items.0.price', 8000)
            ->call('save')
            ->assertHasNoErrors();

        $invoice = Invoice::firstOrFail();

        // Reabrir y guardar sin tocar nada debe conservarlo todo.
        Livewire::actingAs($this->admin())
            ->test(InvoiceForm::class, ['invoice' => $invoice])
            ->call('save')
            ->assertHasNoErrors();

        $item = $invoice->fresh()->items()->firstOrFail();
        $this->assertSame('L', $item->size);
        $this->assertSame('4.50', (string) $item->weight);
        $this->assertSame('Libros', $item->description);
    }
}
