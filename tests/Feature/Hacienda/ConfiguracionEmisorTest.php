<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Settings\CompanySettingsForm;
use App\Models\Branch;
use App\Models\ElectronicBillingSequence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

class ConfiguracionEmisorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function admin(): User
    {
        return User::firstOrCreate(
            ['email' => 'admin@prueba.test'],
            [
                'name' => 'Admin de Prueba', 'username' => 'admin_test',
                'password' => bcrypt('secret'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
            ]
        );
    }

    public function test_los_codigos_de_sucursal_se_editan_desde_la_configuracion(): void
    {
        $this->companySettings();
        $branch = $this->branch();

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->assertSet('branches.0.sucursal_code', '001')
            ->set('branches.0.sucursal_code', '007')
            ->set('branches.0.terminal_code', '00042')
            ->call('save')
            ->assertHasNoErrors();

        $branch->refresh();
        $this->assertSame('007', $branch->sucursal_code);
        $this->assertSame('00042', $branch->terminal_code);
    }

    public function test_los_codigos_se_rellenan_con_ceros(): void
    {
        $this->companySettings();
        $branch = $this->branch();

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->set('branches.0.sucursal_code', '002')
            ->set('branches.0.terminal_code', '00003')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('002', $branch->fresh()->sucursal_code);
        $this->assertSame('00003', $branch->fresh()->terminal_code);
    }

    /**
     * Si dos sucursales comparten sucursal+terminal, ambas numeran por su lado
     * y la segunda choca contra Hacienda con "el comprobante ya existe".
     */
    public function test_dos_sucursales_no_pueden_compartir_el_mismo_par(): void
    {
        $this->companySettings();
        $this->branch();
        Branch::create([
            'name' => 'Alajuela', 'sucursal_code' => '002', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $component = Livewire::actingAs($this->admin())->test(CompanySettingsForm::class);

        // Las dos filas quedarían en 001-00001
        $indice = collect($component->get('branches'))->search(fn ($b) => $b['sucursal_code'] === '002');

        $component->set("branches.{$indice}.sucursal_code", '001')
            ->set("branches.{$indice}.terminal_code", '00001')
            ->call('save')
            ->assertHasErrors("branches.{$indice}.terminal_code");
    }

    public function test_una_sucursal_con_consecutivos_emitidos_queda_bloqueada(): void
    {
        $this->companySettings();
        $branch = $this->branch();
        ElectronicBillingSequence::create([
            'branch_id' => $branch->id, 'document_type' => '01', 'last_number' => 12,
        ]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->assertSet('branches.0.locked', true)
            ->set('branches.0.sucursal_code', '009')
            ->call('save');

        // El código no se movió: cambiarlo rompería el consecutivo de Hacienda.
        $this->assertSame('001', $branch->fresh()->sucursal_code);
    }

    public function test_probar_conexion_avisa_cuando_las_credenciales_sirven(): void
    {
        $this->companySettings();

        Http::fake(['*openid-connect/token' => Http::response(['access_token' => 'tok', 'expires_in' => 300], 200)]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->call('testConnection')
            ->assertSet('connectionTestStatus', 'success')
            ->assertSee('Conexión con Hacienda exitosa');
    }

    public function test_probar_conexion_reporta_el_fallo_en_vez_de_reventar(): void
    {
        $this->companySettings();

        Http::fake(['*openid-connect/token' => Http::response(['error' => 'invalid_grant'], 401)]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->call('testConnection')
            ->assertSet('connectionTestStatus', 'error')
            ->assertSee('Falló la conexión');
    }

    public function test_probar_conexion_pide_guardar_las_credenciales_primero(): void
    {
        $this->companySettings(['atv_username' => null, 'atv_password' => null]);

        Livewire::actingAs($this->admin())
            ->test(CompanySettingsForm::class)
            ->call('testConnection')
            ->assertSet('connectionTestStatus', 'warning')
            ->assertSee('Guardá primero');
    }
}
