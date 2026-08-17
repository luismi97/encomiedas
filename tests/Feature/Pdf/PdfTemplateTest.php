<?php

namespace Tests\Feature\Pdf;

use App\Models\CompanySetting;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Hacienda\BuildsHaciendaFixtures;
use Tests\TestCase;

/**
 * DomPDF soporta un subconjunto muy chico de CSS. Estas plantillas usaban
 * flexbox y un degradado: el degradado no pintaba nada y dejaba el texto
 * blanco del encabezado sobre papel blanco, invisible.
 */
class PdfTemplateTest extends TestCase
{
    use RefreshDatabase, BuildsHaciendaFixtures;

    private const PLANTILLAS = [
        'resources/views/pdf/invoice.blade.php',
        'resources/views/pdf/invoices-report.blade.php',
    ];

    public function test_las_plantillas_no_usan_css_que_dompdf_ignora(): void
    {
        foreach (self::PLANTILLAS as $plantilla) {
            $css = file_get_contents(base_path($plantilla));
            // El comentario explicativo menciona estas tecnicas; solo importan
            // las declaraciones reales, no la prosa.
            $css = preg_replace('#/\*.*?\*/#s', '', $css);

            foreach (['display:flex', 'display: flex', 'linear-gradient(', 'justify-content', 'flex:'] as $noSoportado) {
                $this->assertStringNotContainsString($noSoportado, $css,
                    "{$plantilla} usa {$noSoportado}, que DomPDF ignora.");
            }
        }
    }

    public function test_el_encabezado_tiene_fondo_solido_bajo_el_texto_blanco(): void
    {
        $css = file_get_contents(base_path('resources/views/pdf/invoice.blade.php'));

        preg_match('/\.header\s*\{([^}]*)\}/', $css, $m);
        $this->assertNotEmpty($m, 'No se encontro la regla .header');

        $this->assertMatchesRegularExpression('/background-color:\s*#[0-9a-f]{6}/i', $m[1],
            'El encabezado pinta texto blanco: necesita un fondo solido, no un degradado.');
    }

    public function test_la_factura_se_genera_y_es_un_pdf_valido(): void
    {
        $this->companySettings();
        $invoice = $this->deliveredInvoice($this->branch());
        $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo', 'electronicInvoice']);

        $salida = Pdf::loadView('pdf.invoice', [
            'invoice' => $invoice,
            'company' => CompanySetting::instance(),
        ])->setPaper('a4')->output();

        $this->assertStringStartsWith('%PDF-', $salida);
        $this->assertGreaterThan(5000, strlen($salida));
    }

    public function test_la_ruta_de_descarga_responde_un_pdf(): void
    {
        $this->companySettings();
        $invoice = $this->deliveredInvoice($this->branch());
        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $response = $this->actingAs($admin)->get(route('invoices.pdf', $invoice));

        $response->assertOk();
        $this->assertStringContainsString('application/pdf', $response->headers->get('content-type'));
    }

    public function test_las_lineas_sin_peso_ni_tamano_no_muestran_unidades_sueltas(): void
    {
        $this->companySettings();
        $invoice = $this->deliveredInvoice($this->branch());
        $invoice->items()->update(['weight' => null, 'size' => null]);
        $invoice->load(['items', 'taxes', 'pickupBranch', 'deliveryBranch', 'creator', 'assignedTo', 'electronicInvoice']);

        $html = view('pdf.invoice', [
            'invoice' => $invoice,
            'company' => CompanySetting::instance(),
        ])->render();

        $this->assertStringNotContainsString('<td> kg</td>', $html);
        $this->assertStringContainsString('—', $html);
    }
}
