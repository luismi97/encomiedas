<?php

namespace Tests\Feature\Hacienda;

use App\Models\ElectronicInvoice;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * El recibo del cliente lleva el consecutivo y la clave numérica del
 * comprobante: es con lo que lo busca en su contabilidad y en el correo.
 */
class ClaveEnElReciboTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function admin(): User
    {
        return User::where('role', User::ROLE_ADMIN)->firstOrFail();
    }

    public function test_el_recibo_lleva_consecutivo_y_clave(): void
    {
        Bus::fake();
        $this->companySettings();
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);

        $this->actingAs($this->admin())
            ->get(route('invoices.recibo', $guia))
            ->assertOk()
            ->assertSee('Clave numérica')
            ->assertSee($comprobante->clave)
            ->assertSee('Consecutivo')
            ->assertSee($comprobante->consecutivo);
    }

    public function test_sin_comprobante_el_recibo_no_inventa_clave(): void
    {
        $this->companySettings();
        $guia = $this->deliveredInvoice($this->branch());

        $this->actingAs($this->admin())
            ->get(route('invoices.recibo', $guia))
            ->assertOk()
            ->assertDontSee('Clave numérica');
    }

    /** Una clave rechazada se rehace con otra: la impresa no serviría. */
    public function test_una_clave_rechazada_no_se_imprime(): void
    {
        Bus::fake();
        $this->companySettings();
        $guia = $this->deliveredInvoice($this->branch());
        $comprobante = app(ElectronicBillingService::class)->queueForInvoice($guia);
        $comprobante->forceFill(['status' => ElectronicInvoice::STATUS_REJECTED])->save();

        $this->actingAs($this->admin())
            ->get(route('invoices.recibo', $guia))
            ->assertOk()
            ->assertDontSee($comprobante->clave);
    }
}
