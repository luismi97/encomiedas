<?php

namespace Tests\Feature\Operacion;

use App\Livewire\Settings\CompanySettingsForm;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\CambioDeEstadoGuia;
use App\Services\GuideStatusService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Los avisos al destinatario se pueden apagar uno por uno, para no gastar el
 * cupo de correos del hosting en lo que la empresa no necesita.
 */
class CorreosOpcionalesTest extends TestCase
{
    use RefreshDatabase;

    private Branch $sj;
    private Branch $lim;
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $this->lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true]);

        $this->admin = User::create(['name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true]);
    }

    private function guiaEnCamino(): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_DISPATCHED,
            'pickup_branch_id' => $this->sj->id,
            'delivery_branch_id' => $this->lim->id,
            'sender_name' => 'R', 'recipient_name' => 'D',
            'recipient_email' => 'destinatario@t.test',
            'subtotal' => 10000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 10000,
            'payment_method' => 'cash',
            'created_by' => $this->admin->id,
        ])->fresh();
    }

    private function llegar(Invoice $guia): void
    {
        app(GuideStatusService::class)->cambiar($guia, Invoice::STATUS_AT_DESTINATION, source: GuideStatusHistory::SOURCE_SYSTEM);
    }

    public function test_sin_tocar_nada_el_aviso_sale_como_siempre(): void
    {
        Notification::fake();

        $this->llegar($this->guiaEnCamino());

        Notification::assertSentOnDemandTimes(CambioDeEstadoGuia::class, 1);
    }

    public function test_con_el_aviso_apagado_no_sale(): void
    {
        Notification::fake();
        CompanySetting::instance()->forceFill(['mail_aviso_en_destino' => false])->save();

        $this->llegar($this->guiaEnCamino());

        Notification::assertNothingSent();
    }

    /** Apagar uno no apaga los demás. */
    public function test_cada_aviso_se_apaga_por_separado(): void
    {
        Notification::fake();
        CompanySetting::instance()->forceFill(['mail_aviso_entregado' => false])->save();

        $this->llegar($this->guiaEnCamino());

        Notification::assertSentOnDemandTimes(CambioDeEstadoGuia::class, 1);
    }

    public function test_se_configuran_desde_la_pantalla_de_la_empresa(): void
    {
        $this->assertTrue(CompanySetting::instance()->mail_copia_comprobantes, 'Arrancan encendidos.');

        Livewire::actingAs($this->admin)
            ->test(CompanySettingsForm::class)
            ->assertSee('Correos automáticos')
            ->assertSet('correos.mail_copia_comprobantes', true)
            ->set('name', 'Encomiendas S.A.')
            ->set('identification_number', '3101123456')
            ->set('activity_code', '4923.0')
            ->set('province', '1')
            ->set('canton', '01')
            ->set('district', '01')
            ->set('correos.mail_copia_comprobantes', false)
            ->set('correos.mail_aviso_por_desechar', false)
            ->call('save')
            ->assertHasNoErrors();

        $config = CompanySetting::instance()->fresh();
        $this->assertFalse($config->mail_copia_comprobantes);
        $this->assertFalse($config->mail_aviso_por_desechar);
        $this->assertTrue($config->mail_aviso_en_destino);
        $this->assertTrue($config->mail_aviso_entregado);
    }
}
