<?php

namespace Tests\Feature\Empresas;

use App\Models\Company;
use App\Models\CompanySetting;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

/**
 * Cada empresa factura con lo suyo.
 *
 * Es la parte del aislamiento donde un error no se ve: si el certificado o las
 * credenciales de ATV salieran de la empresa equivocada, el comprobante se
 * firmaría a nombre de otro contribuyente y Hacienda lo aceptaría. Lo que
 * rebota no es el sistema: es una declaración de impuestos ajena.
 */
class ConfiguracionPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Company $otra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otra = Company::create([
            'name' => 'Transportes Vecinos', 'slug' => 'transportes-vecinos', 'is_active' => true,
        ]);
    }

    public function test_cada_empresa_tiene_su_propia_configuracion(): void
    {
        CompanySetting::instance()->update(['identification_number' => '3101111111']);

        CompanyContext::para($this->otra, function () {
            CompanySetting::instance()->update(['identification_number' => '3102222222']);
        });

        $this->assertSame('3101111111', CompanySetting::instance()->identification_number);

        $ajena = CompanyContext::para($this->otra, fn () => CompanySetting::instance());
        $this->assertSame('3102222222', $ajena->identification_number);
    }

    public function test_la_configuracion_no_se_cruza_entre_empresas(): void
    {
        CompanySetting::instance();
        CompanyContext::para($this->otra, fn () => CompanySetting::instance());

        $this->assertSame(1, CompanySetting::count(), 'La empresa activa ve una sola: la suya.');
        $this->assertSame(2, CompanySetting::withoutGlobalScopes()->count());
    }

    /**
     * Fuera de contexto y con varias empresas, se niega a adivinar.
     *
     * El caso real es un comando o un trabajo de la cola que se olvidó de fijar
     * la empresa. Devolver «la primera fila que aparezca» firmaría con el
     * certificado de otro; reventar manda a arreglar la llamada.
     */
    public function test_sin_empresa_en_contexto_y_con_varias_no_adivina(): void
    {
        CompanySetting::instance();
        CompanyContext::para($this->otra, fn () => CompanySetting::instance());

        CompanyContext::olvidar();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CompanyContext::para');

        CompanySetting::instance();
    }

    /**
     * Con una sola empresa no hay ambigüedad que resolver.
     *
     * Es lo que permite que los comandos de siempre —los de la instalación de
     * un solo cliente— sigan funcionando sin tener que envolverlos.
     */
    public function test_con_una_sola_empresa_sigue_funcionando_sin_contexto(): void
    {
        $this->otra->delete();
        CompanySetting::instance();

        CompanyContext::olvidar();

        $this->assertNotNull(CompanySetting::instance());
    }
}
