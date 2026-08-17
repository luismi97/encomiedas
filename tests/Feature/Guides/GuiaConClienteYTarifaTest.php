<?php

namespace Tests\Feature\Guides;

use App\Livewire\Invoices\InvoiceForm;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Rate;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Conecta lo de la etapa 1 con el formulario de guía: elegir un cliente
 * registrado precarga sus datos, y el tarifario propone el precio.
 */
class GuiaConClienteYTarifaTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        Tax::create(['name' => 'IVA general', 'percent' => 13, 'hacienda_code' => '08', 'is_default' => true, 'is_active' => true]);
    }

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
            ->test(InvoiceForm::class)
            ->set('pickup_branch_id', $this->sj->id)
            ->set('delivery_branch_id', $this->lim->id);
    }

    public function test_elegir_un_remitente_registrado_precarga_sus_datos(): void
    {
        $cliente = Customer::create([
            'name' => 'Ferretería El Roble', 'phone' => '2222-3333',
            'identification' => '3101778899', 'identification_type' => '02',
            'branch_id' => $this->sj->id,
        ]);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->assertSet('sender_name', 'Ferretería El Roble')
            ->assertSet('sender_phone', '2222-3333')
            ->assertSet('sender_identification', '3101778899');
    }

    /** Con cédula del receptor la guía puede salir como Factura Electrónica. */
    public function test_un_receptor_con_cedula_activa_la_factura_electronica(): void
    {
        $cliente = Customer::create([
            'name' => 'José Fernández', 'email' => 'jose@cliente.test',
            'identification' => '112340567', 'identification_type' => '01',
        ]);

        $this->formulario()
            ->set('recipient_customer_id', $cliente->id)
            ->assertSet('recipient_name', 'José Fernández')
            ->assertSet('recipient_email', 'jose@cliente.test')
            ->assertSet('recipient_identification', '112340567')
            ->assertSet('wantsInvoice', true);
    }

    public function test_un_receptor_sin_cedula_no_activa_la_factura(): void
    {
        $cliente = Customer::create(['name' => 'Marta Solano', 'phone' => '8811-2233']);

        $this->formulario()
            ->set('recipient_customer_id', $cliente->id)
            ->assertSet('recipient_name', 'Marta Solano')
            ->assertSet('wantsInvoice', false);
    }

    public function test_el_tarifario_propone_el_precio_de_cada_paquete(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2500]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 5000]);

        $componente = $this->formulario()
            ->set('items.0.package_code', 'PKG-1')
            ->set('items.0.weight', 3)
            ->call('addItem')
            ->set('items.1.package_code', 'PKG-2')
            ->set('items.1.weight', 8)
            ->call('cotizar');

        // Cada paquete entra en su propio rango: 2500 + 5000.
        $componente->assertSet('items.0.price', 2500.0)
            ->assertSet('items.1.price', 5000.0)
            ->assertSee('₡7,500.00');
    }

    /** Dos cajas de 3 kg no pagan lo mismo que una de 6: cada una cotiza sola. */
    public function test_cada_paquete_cotiza_por_su_cuenta(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2500]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 5000]);

        $this->formulario()
            ->set('items.0.weight', 3)
            ->call('addItem')
            ->set('items.1.weight', 3)
            ->call('cotizar')
            ->assertSet('items.0.price', 2500.0)
            ->assertSet('items.1.price', 2500.0);
    }

    public function test_la_cotizacion_usa_el_peso_volumetrico(): void
    {
        Rate::create(['name' => 'Liviana', 'min_weight' => 0, 'max_weight' => 5, 'price' => 2500]);
        Rate::create(['name' => 'Media', 'min_weight' => 5, 'max_weight' => 20, 'price' => 5000]);

        // 2 kg reales pero 6 volumétricos: cae en «Media».
        $this->formulario()
            ->set('items.0.weight', 2)
            ->set('items.0.length_cm', 40)
            ->set('items.0.width_cm', 30)
            ->set('items.0.height_cm', 25)
            ->call('cotizar')
            ->assertSet('items.0.price', 5000.0);
    }

    public function test_sin_tarifa_avisa_en_vez_de_poner_cero(): void
    {
        $this->formulario()
            ->set('items.0.weight', 3)
            ->call('cotizar')
            ->assertSee('no tiene tarifa para esta ruta');
    }

    public function test_la_guia_guarda_cliente_dimensiones_y_tipo_de_envio(): void
    {
        $remitente = Customer::create(['name' => 'Ferretería El Roble', 'identification' => '3101778899', 'identification_type' => '02']);

        $this->formulario()
            ->set('sender_customer_id', $remitente->id)
            ->set('recipient_name', 'José Fernández')
            ->set('shipment_type', Rate::TYPE_ENVELOPE)
            ->set('declared_value', 25000)
            ->set('items.0.package_code', 'PKG-1')
            ->set('items.0.weight', 2)
            ->set('items.0.length_cm', 30)
            ->set('items.0.width_cm', 20)
            ->set('items.0.height_cm', 10)
            ->set('items.0.price', 3000)
            ->call('save')
            ->assertHasNoErrors();

        $guia = Invoice::firstOrFail();

        $this->assertSame($remitente->id, $guia->sender_customer_id);
        $this->assertSame(Rate::TYPE_ENVELOPE, $guia->shipment_type);
        $this->assertSame('25000.00', (string) $guia->declared_value);

        $paquete = $guia->items()->firstOrFail();
        $this->assertEqualsWithDelta(30, (float) $paquete->length_cm, 0.01);
        $this->assertEqualsWithDelta(20, (float) $paquete->width_cm, 0.01);
        $this->assertEqualsWithDelta(10, (float) $paquete->height_cm, 0.01);
    }

    /**
     * La guía es un documento: copia los datos del cliente en vez de solo
     * referenciarlo, para que un cambio de teléfono el año que viene no
     * reescriba lo que decía una guía vieja.
     */
    public function test_los_datos_se_copian_y_no_se_referencian(): void
    {
        $cliente = Customer::create(['name' => 'Marta Solano', 'phone' => '8811-2233']);

        $this->formulario()
            ->set('sender_customer_id', $cliente->id)
            ->set('recipient_name', 'José')
            ->set('items.0.package_code', 'PKG-1')
            ->set('items.0.price', 1000)
            ->call('save')
            ->assertHasNoErrors();

        $cliente->update(['phone' => '7000-0000']);

        $this->assertSame('8811-2233', Invoice::firstOrFail()->sender_phone);
    }
}
