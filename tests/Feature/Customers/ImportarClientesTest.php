<?php

namespace Tests\Feature\Customers;

use App\Livewire\Customers\CustomerImport;
use App\Models\Branch;
use App\Models\Customer;
use App\Models\User;
use App\Services\ImportadorDeClientes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Carga de clientes desde un CSV.
 *
 * La cartera existe antes que el sistema, en una hoja de cálculo que alguien
 * mantiene desde hace años. Lo que se prueba acá es que esa hoja entre tal como
 * está —con punto y coma, con acentos, con cédulas escritas con guiones— y que
 * lo que no se pueda importar se diga con el número de fila, no con un error
 * general que obliga a adivinar.
 */
class ImportarClientesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private Branch $sj;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sj = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true]);
    }

    private function importador(): ImportadorDeClientes
    {
        return app(ImportadorDeClientes::class);
    }

    /** Escribe un CSV temporal y devuelve su ruta. */
    private function archivo(string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'clientes') . '.csv';
        file_put_contents($ruta, $contenido);

        return $ruta;
    }

    // ── La plantilla ──────────────────────────────────────────────────

    public function test_la_plantilla_se_descarga_con_los_encabezados_reales(): void
    {
        $respuesta = $this->actingAs($this->admin)->get(route('customers.plantilla'));

        $respuesta->assertOk();
        $contenido = $respuesta->streamedContent();

        foreach (array_keys(ImportadorDeClientes::COLUMNAS) as $columna) {
            $this->assertStringContainsString($columna, $contenido);
        }
    }

    /** Sin BOM, Excel abre el archivo y destroza todos los acentos. */
    public function test_la_plantilla_lleva_bom_para_que_excel_respete_los_acentos(): void
    {
        $this->assertStringStartsWith("\u{FEFF}", $this->importador()->plantilla());
    }

    /** La plantilla tiene que poder volver a entrar por el importador. */
    public function test_la_plantilla_se_importa_a_si_misma(): void
    {
        $ruta = $this->archivo($this->importador()->plantilla());

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame([], $resultado['errores']);
        $this->assertSame(2, $resultado['total']);
        $this->assertTrue(collect($resultado['filas'])->every(fn ($f) => $f['importable']));
    }

    // ── Lectura del archivo ───────────────────────────────────────────

    public function test_lee_archivos_con_punto_y_coma_y_con_coma(): void
    {
        $conPuntoYComa = $this->archivo("nombre;identificacion\nMarta Solano;112340567\n");
        $conComa       = $this->archivo("nombre,identificacion\nMarta Solano,112340567\n");

        foreach ([$conPuntoYComa, $conComa] as $ruta) {
            $resultado = $this->importador()->analizar($ruta);

            $this->assertSame(1, $resultado['total']);
            $this->assertSame('Marta Solano', $resultado['filas'][0]['nombre']);
            $this->assertSame('112340567', $resultado['filas'][0]['datos']['identification']);
        }
    }

    /** «Identificación» y «identificacion» son la misma columna. */
    public function test_los_encabezados_valen_con_acentos_y_en_mayusculas(): void
    {
        $ruta = $this->archivo("Nombre;Identificación;Condición de pago\nMarta;112340567;Contado\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame('Marta', $resultado['filas'][0]['nombre']);
        $this->assertSame('112340567', $resultado['filas'][0]['datos']['identification']);
    }

    /** Es como la escribe todo el país; rechazarla sería rechazar el país. */
    public function test_la_cedula_con_guiones_se_limpia_sola(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;1-1234-0567\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame('112340567', $resultado['filas'][0]['datos']['identification']);
        $this->assertTrue($resultado['filas'][0]['importable']);
    }

    public function test_un_archivo_sin_columna_nombre_se_rechaza_entero(): void
    {
        $ruta = $this->archivo("cedula;correo\n112340567;a@b.cr\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame(0, $resultado['total']);
        $this->assertStringContainsString('nombre', $resultado['errores'][0]);
    }

    public function test_las_filas_en_blanco_se_saltan_sin_quejarse(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\n;\n\nKenneth;203450678\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame(2, $resultado['total']);
    }

    // ── Validación fila por fila ──────────────────────────────────────

    /** El problema se señala con el número de fila del archivo, no en general. */
    public function test_una_fila_sin_nombre_no_entra_y_dice_cual_es(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\n;203450678\n");

        $resultado = $this->importador()->analizar($ruta);
        $mala = collect($resultado['filas'])->firstWhere('importable', false);

        $this->assertSame(3, $mala['numero']);
        $this->assertStringContainsString('Sin nombre', $mala['problemas'][0]);
    }

    public function test_una_cedula_corta_se_señala(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;123\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertFalse($resultado['filas'][0]['importable']);
        $this->assertStringContainsString('9 y 12 dígitos', $resultado['filas'][0]['problemas'][0]);
    }

    /** Hacienda exige receptor identificado para facturarle al cierre. */
    public function test_un_cliente_de_credito_sin_cedula_no_entra(): void
    {
        $ruta = $this->archivo("nombre;identificacion;condicion_pago\nEl Roble;;credito\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertFalse($resultado['filas'][0]['importable']);
        $this->assertStringContainsString('crédito necesita identificación', $resultado['filas'][0]['problemas'][0]);
    }

    public function test_la_cedula_repetida_dentro_del_archivo_se_detecta(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\nMarta otra vez;112340567\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertTrue($resultado['filas'][0]['importable']);
        $this->assertFalse($resultado['filas'][1]['importable']);
        $this->assertStringContainsString('fila 2', $resultado['filas'][1]['problemas'][0]);
    }

    public function test_la_sucursal_se_reconoce_por_nombre_y_por_prefijo(): void
    {
        $ruta = $this->archivo("nombre;sede\nMarta;SJ\nKenneth;San José\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertSame($this->sj->id, $resultado['filas'][0]['datos']['branch_id']);
        $this->assertSame($this->sj->id, $resultado['filas'][1]['datos']['branch_id']);
    }

    /** Una sucursal inventada no bota la fila: el cliente sirve igual. */
    public function test_una_sucursal_inexistente_avisa_pero_deja_pasar_la_fila(): void
    {
        $ruta = $this->archivo("nombre;sede\nMarta;Cartago\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertNull($resultado['filas'][0]['datos']['branch_id']);
        $this->assertStringContainsString('Cartago', $resultado['filas'][0]['avisos'][0]);
        $this->assertSame([], $resultado['filas'][0]['problemas']);
        $this->assertTrue($resultado['filas'][0]['importable']);
    }

    public function test_el_credito_trae_limite_y_dia_de_corte(): void
    {
        $ruta = $this->archivo("nombre;identificacion;condicion_pago;limite_credito;dia_corte\n"
            . "El Roble;3101234567;credito;500000;15\n");

        $datos = $this->importador()->analizar($ruta)['filas'][0]['datos'];

        $this->assertSame(Customer::PAYMENT_CREDIT, $datos['payment_condition']);
        $this->assertSame(500000.0, $datos['credit_limit']);
        $this->assertSame(15, $datos['credit_cutoff_day']);
    }

    /** Un cliente de contado no arrastra límite ni día de corte. */
    public function test_el_contado_no_guarda_datos_de_credito(): void
    {
        $ruta = $this->archivo("nombre;identificacion;condicion_pago;limite_credito;dia_corte\n"
            . "Marta;112340567;contado;500000;15\n");

        $datos = $this->importador()->analizar($ruta)['filas'][0]['datos'];

        $this->assertSame(0.0, $datos['credit_limit']);
        $this->assertNull($datos['credit_cutoff_day']);
    }

    // ── La escritura ──────────────────────────────────────────────────

    public function test_analizar_no_escribe_nada(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\n");

        $this->importador()->analizar($ruta);

        $this->assertSame(0, Customer::count());
    }

    public function test_importar_crea_los_clientes_sanos_y_omite_los_demas(): void
    {
        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\n;203450678\nKenneth;203450678\n");

        $resultado = $this->importador()->analizar($ruta);
        $resumen = $this->importador()->importar($resultado['filas']);

        $this->assertSame(2, $resumen['creados']);
        $this->assertSame(1, $resumen['omitidos']);
        $this->assertSame(2, Customer::count());
    }

    /**
     * La fila sin nombre se rechaza, pero su cédula no queda reservada: si la
     * reservara, se llevaría puesta a la fila buena de más abajo y se perderían
     * las dos por un problema que era de una sola.
     */
    public function test_una_fila_rechazada_no_le_quita_la_cedula_a_la_siguiente(): void
    {
        $ruta = $this->archivo("nombre;identificacion\n;203450678\nKenneth;203450678\n");

        $resultado = $this->importador()->analizar($ruta);

        $this->assertFalse($resultado['filas'][0]['importable']);
        $this->assertTrue($resultado['filas'][1]['importable']);
    }

    public function test_por_defecto_no_toca_a_los_que_ya_existen(): void
    {
        Customer::create(['name' => 'Marta vieja', 'identification' => '112340567', 'identification_type' => '01']);

        $ruta = $this->archivo("nombre;identificacion\nMarta nueva;112340567\n");
        $resultado = $this->importador()->analizar($ruta);

        $resumen = $this->importador()->importar($resultado['filas']);

        $this->assertSame(0, $resumen['creados']);
        $this->assertSame(1, $resumen['omitidos']);
        $this->assertSame('Marta vieja', Customer::first()->name);
    }

    public function test_si_se_pide_expresamente_si_los_actualiza(): void
    {
        Customer::create(['name' => 'Marta vieja', 'identification' => '112340567', 'identification_type' => '01']);

        $ruta = $this->archivo("nombre;identificacion\nMarta nueva;112340567\n");
        $resultado = $this->importador()->analizar($ruta);

        $resumen = $this->importador()->importar($resultado['filas'], actualizarExistentes: true);

        $this->assertSame(1, $resumen['actualizados']);
        $this->assertSame('Marta nueva', Customer::first()->name);
    }

    /** Si alguien lo desactivó a propósito, una carga masiva no lo revive. */
    public function test_actualizar_no_reactiva_a_un_cliente_dado_de_baja(): void
    {
        Customer::create(['name' => 'Marta', 'identification' => '112340567',
            'identification_type' => '01', 'is_active' => false]);

        $ruta = $this->archivo("nombre;identificacion\nMarta;112340567\n");
        $resultado = $this->importador()->analizar($ruta);
        $this->importador()->importar($resultado['filas'], actualizarExistentes: true);

        $this->assertFalse(Customer::first()->is_active);
    }

    // ── La pantalla ───────────────────────────────────────────────────

    public function test_la_pantalla_revisa_antes_de_escribir(): void
    {
        $csv = "nombre;identificacion\nMarta;112340567\n;203450678\n";

        Livewire::actingAs($this->admin)
            ->test(CustomerImport::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('clientes.csv', $csv))
            ->call('analizar')
            ->assertSet('analizado', true)
            ->assertSee('No entra');

        $this->assertSame(0, Customer::count(), 'Revisar el archivo no puede escribir nada.');
    }

    public function test_la_pantalla_importa_y_deja_el_resumen(): void
    {
        $csv = "nombre;identificacion\nMarta;112340567\nKenneth;203450678\n";

        Livewire::actingAs($this->admin)
            ->test(CustomerImport::class)
            ->set('archivo', UploadedFile::fake()->createWithContent('clientes.csv', $csv))
            ->call('analizar')
            ->call('importar')
            ->assertSet('resumen.creados', 2)
            // El archivo se descarga del componente: dejarlo cargado invita a
            // importarlo dos veces.
            ->assertSet('analizado', false);

        $this->assertSame(2, Customer::count());
    }

    public function test_no_se_puede_importar_sin_revisar(): void
    {
        Livewire::actingAs($this->admin)
            ->test(CustomerImport::class)
            ->call('importar')
            ->assertSet('feedbackType', 'error');

        $this->assertSame(0, Customer::count());
    }
}
