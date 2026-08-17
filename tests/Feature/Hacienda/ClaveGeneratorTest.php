<?php

namespace Tests\Feature\Hacienda;

use App\Services\Hacienda\ClaveGenerator;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClaveGeneratorTest extends TestCase
{
    use RefreshDatabase;
    use BuildsHaciendaFixtures;

    public function test_la_clave_tiene_50_digitos_con_la_estructura_de_hacienda(): void
    {
        $branch = $this->branch();
        $issuedAt = Carbon::create(2026, 8, 14, 10, 30, 0, 'America/Costa_Rica');

        $clave = app(ClaveGenerator::class)->generate($branch, '01', '3101123456', $issuedAt);

        $this->assertMatchesRegularExpression('/^\d{50}$/', $clave['clave']);

        // país(3) + ddmmaa(6) + cédula(12) + consecutivo(20) + situación(1) + seguridad(8)
        $this->assertSame('506', substr($clave['clave'], 0, 3));
        $this->assertSame('140826', substr($clave['clave'], 3, 6));
        $this->assertSame('003101123456', substr($clave['clave'], 9, 12));
        $this->assertSame($clave['consecutivo'], substr($clave['clave'], 21, 20));
        $this->assertSame('1', substr($clave['clave'], 41, 1));
        $this->assertSame($clave['security_code'], substr($clave['clave'], 42, 8));
    }

    public function test_el_consecutivo_tiene_20_digitos_y_avanza_de_uno_en_uno(): void
    {
        $branch = $this->branch();
        $generator = app(ClaveGenerator::class);
        $issuedAt = Carbon::now();

        $first  = $generator->generate($branch, '01', '3101123456', $issuedAt);
        $second = $generator->generate($branch, '01', '3101123456', $issuedAt);

        // sucursal(3) + terminal(5) + tipo(2) + secuencia(10)
        $this->assertSame('001' . '00001' . '01' . '0000000001', $first['consecutivo']);
        $this->assertSame('001' . '00001' . '01' . '0000000002', $second['consecutivo']);
    }

    public function test_cada_tipo_de_documento_lleva_su_propia_secuencia(): void
    {
        $branch = $this->branch();
        $generator = app(ClaveGenerator::class);
        $issuedAt = Carbon::now();

        $factura = $generator->generate($branch, '01', '3101123456', $issuedAt);
        $nota    = $generator->generate($branch, '03', '3101123456', $issuedAt);

        $this->assertSame('0000000001', substr($factura['consecutivo'], -10));
        $this->assertSame('0000000001', substr($nota['consecutivo'], -10));
        $this->assertSame('01', substr($factura['consecutivo'], 8, 2));
        $this->assertSame('03', substr($nota['consecutivo'], 8, 2));
    }
}
