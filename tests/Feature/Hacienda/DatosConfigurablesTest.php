<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Settings\CompanySettingsForm;
use App\Models\CompanySetting;
use App\Services\Hacienda\Catalogs;
use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Nada de lo que identifica al emisor puede quedar escrito en el código: todo
 * sale de company_settings. Un valor quemado se descubre cuando Hacienda lo
 * rechaza, no antes.
 */
class DatosConfigurablesTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    /** Cada columna de configuración debe poder editarse desde la pantalla. */
    public function test_toda_la_configuracion_es_editable(): void
    {
        $componente = file_get_contents(base_path('app/Livewire/Settings/CompanySettingsForm.php'));

        $columnas = collect(Schema::getColumnListing('company_settings'))
            ->reject(fn ($c) => in_array($c, [
                'id', 'created_at', 'updated_at',
                'certificate_path', // lo escribe la subida del archivo, no se digita
            ]));

        $faltantes = $columnas->reject(
            fn ($c) => str_contains($componente, "'{$c}'") || str_contains($componente, '$this->' . $c)
        );

        $this->assertEmpty(
            $faltantes->all(),
            'Columnas de configuración sin control en pantalla: ' . $faltantes->implode(', ')
        );
    }

    /** El XML tiene que reflejar lo guardado, no valores por defecto. */
    public function test_el_xml_toma_los_datos_del_emisor_de_la_configuracion(): void
    {
        $this->companySettings([
            'name'                  => 'Transportes Ejemplo S.A.',
            'identification_number' => '3101999888',
            'activity_code'         => '492300',
            'province'              => '3',
            'canton'                => '05',
            'district'              => '02',
            'phone_code'            => '507',
            'phone'                 => '88887777',
            'email'                 => 'facturas@ejemplo.test',
        ]);

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        foreach ([
            '<Nombre>Transportes Ejemplo S.A.</Nombre>',
            '<Numero>3101999888</Numero>',
            '<CodigoActividadEmisor>492300</CodigoActividadEmisor>',
            '<Provincia>3</Provincia>',
            '<Canton>05</Canton>',
            '<Distrito>02</Distrito>',
            '<CodigoPais>507</CodigoPais>',
            '<NumTelefono>88887777</NumTelefono>',
            '<CorreoElectronico>facturas@ejemplo.test</CorreoElectronico>',
        ] as $esperado) {
            $this->assertStringContainsString($esperado, $xml);
        }
    }

    public function test_el_cabys_de_la_configuracion_gana_sobre_el_del_archivo(): void
    {
        $this->companySettings(['default_cabys_code' => '6512300000000']);

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        $this->assertStringContainsString('<CodigoCABYS>6512300000000</CodigoCABYS>', $xml);
        $this->assertStringNotContainsString(config('hacienda.default_cabys_code'), $xml);
    }

    /**
     * La condición de venta se mostraba como "Contado" fijo en el PDF y en la
     * pantalla mientras el XML la sacaba de la configuración: si cambiaba, el
     * cliente veía una cosa y Hacienda recibía otra.
     */
    public function test_la_condicion_de_venta_sale_de_una_sola_fuente(): void
    {
        config(['hacienda.sale_condition' => '02']);

        $this->assertSame('Crédito', Catalogs::saleConditionLabel());

        $pdf = file_get_contents(base_path('resources/views/pdf/invoice.blade.php'));
        $show = file_get_contents(base_path('resources/views/livewire/invoices/invoice-show.blade.php'));

        $this->assertStringNotContainsString('venta: Contado', $pdf);
        $this->assertStringNotContainsString('Condición</span>Contado', $show);
    }

    public function test_la_tarifa_de_iva_sale_de_la_tabla_de_impuestos(): void
    {
        $this->companySettings();
        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        // El fixture aplica IVA 13 desde invoice_taxes, no desde config.
        $this->assertStringContainsString('<Tarifa>13.00</Tarifa>', $xml);
    }

    public function test_la_unidad_de_medida_sigue_la_clasificacion_del_cabys(): void
    {
        $this->companySettings(['default_cabys_code' => '2441099000000']);

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        $xml = (new FacturaElectronicaXml($ei->fresh()))->build();

        // CABYS de bien: declarar 'Sp' (servicios prestados) se contradice.
        $this->assertStringContainsString('<UnidadMedida>Unid</UnidadMedida>', $xml);
    }
}
