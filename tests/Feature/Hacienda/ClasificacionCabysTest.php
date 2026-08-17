<?php

namespace Tests\Feature\Hacienda;

use App\Services\Hacienda\ElectronicBillingService;
use App\Services\Hacienda\FacturaElectronicaXml;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Hacienda decide si una línea es mercancía o servicio por el CABYS, no por la
 * unidad de medida. Declararlo todo como servicio con un CABYS de bien produce
 * el par de rechazos -111 y -110.
 */
class ClasificacionCabysTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    private function xmlCon(string $cabys): string
    {
        $this->companySettings(['default_cabys_code' => $cabys]);

        $ei = app(ElectronicBillingService::class)
            ->queueForInvoice($this->deliveredInvoice($this->branch()));

        return (new FacturaElectronicaXml($ei->fresh()))->build();
    }

    public function test_un_cabys_de_servicio_va_a_total_serv_gravados(): void
    {
        // 8511200000000 = sección 8, servicios.
        $xml = $this->xmlCon('8511200000000');

        $this->assertStringContainsString('<TotalServGravados>10000', $xml);
        $this->assertStringNotContainsString('<TotalMercanciasGravadas>', $xml);
    }

    public function test_un_cabys_de_mercancia_va_a_total_mercancias_gravadas(): void
    {
        // 2441099000000 = sección 2, bienes (cuero y calzado).
        $xml = $this->xmlCon('2441099000000');

        $this->assertStringContainsString('<TotalMercanciasGravadas>10000', $xml);
        $this->assertStringNotContainsString('<TotalServGravados>', $xml);
    }

    public function test_el_total_gravado_es_el_mismo_con_cualquier_clasificacion(): void
    {
        // Lo que cambia es en qué renglón cae, no el total: si el total gravado
        // se moviera, el rechazo sería por descuadre de montos, no de tipo.
        $xml = $this->xmlCon('2441099000000');

        $this->assertStringContainsString('<TotalGravado>10000', $xml);
        $this->assertStringContainsString('<TotalVenta>10000', $xml);
    }

    public function test_la_frontera_de_seccion_esta_en_el_6(): void
    {
        $builder = new class extends FacturaElectronicaXml {
            public function __construct() {}
            public function esServicio(?string $cabys): bool { return $this->isService($cabys); }
        };

        $this->assertFalse($builder->esServicio('0111100000000'), 'sección 0 = mercancía');
        $this->assertFalse($builder->esServicio('2441099000000'), 'sección 2 = mercancía');
        $this->assertFalse($builder->esServicio('5111100000000'), 'sección 5 = mercancía');
        $this->assertTrue($builder->esServicio('6511100000000'), 'sección 6 = servicio');
        $this->assertTrue($builder->esServicio('8511200000000'), 'sección 8 = servicio');
        $this->assertFalse($builder->esServicio(null), 'sin CABYS no se asume servicio');
        $this->assertFalse($builder->esServicio(''), 'CABYS vacío no se asume servicio');
    }
}
