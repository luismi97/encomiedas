<?php

namespace Tests\Feature\Ui;

use App\Models\Branch;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Un campo numérico no puede tumbar la pantalla, escriba lo que escriba.
 *
 * Un <input type="number"> manda TEXTO mientras se escribe: "" al borrarlo,
 * "." o "-" al empezar un decimal o un negativo, "1,000" si alguien pega un
 * monto con separador. Nada de eso se puede asignar a una propiedad `float`
 * tipada: Livewire la deja SIN INICIALIZAR y el componente muere con
 * «Property [$x] not found on component», nombrando el campo que se tocó —lo
 * que hacía parecer un problema distinto cada vez—.
 *
 * El guardián lee los campos de la pantalla renderizada, no las declaraciones
 * de la clase: así sigue sirviendo aunque cambien los tipos, y cubre los
 * campos nuevos sin que nadie lo actualice.
 */
class CamposNumericosVaciosTest extends TestCase
{
    use RefreshDatabase;

    /** Lo que el navegador manda de verdad mientras alguien escribe. */
    private const ENTRADAS_HOSTILES = ['', '.', '-', '1,000', 'abc', '0', '1.'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $sj = Branch::create(['name'=>'San José','prefix'=>'SJ','sucursal_code'=>'001','terminal_code'=>'00001','is_active'=>true]);
        Branch::create(['name'=>'Limón','prefix'=>'LIM','sucursal_code'=>'006','terminal_code'=>'00001','is_active'=>true]);
        Tax::create(['name'=>'IVA','percent'=>13,'hacienda_code'=>'08','is_default'=>true,'is_active'=>true]);

        $this->admin = User::create(['name'=>'Admin','username'=>'admin','email'=>'a@t.test',
            'password'=>bcrypt('x'),'role'=>User::ROLE_ADMIN,'is_active'=>true,'branch_id'=>$sj->id]);
    }

    /** @return array<string,array{0:string,1:string}> */
    public static function componentes(): array
    {
        return [
            'nueva guía'      => [\App\Livewire\Invoices\InvoiceForm::class, null],
            'tarifario'       => [\App\Livewire\Rates\RateIndex::class, 'create'],
            'impuestos'       => [\App\Livewire\Taxes\TaxIndex::class, 'create'],
            'clientes'        => [\App\Livewire\Customers\CustomerIndex::class, 'create'],
            'caja'            => [\App\Livewire\Caja\CajaPanel::class, null],
            'crédito'         => [\App\Livewire\Credito\CreditoPanel::class, null],
            'sucursales'      => [\App\Livewire\Branches\BranchIndex::class, 'create'],
            'tipos de bulto'  => [\App\Livewire\PackageTypes\PackageTypeIndex::class, 'create'],
            'empresa'         => [\App\Livewire\Settings\CompanySettingsForm::class, null],
            'cotizaciones'    => [\App\Livewire\Quotes\QuoteIndex::class, 'create'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('componentes')]
    public function test_ningun_campo_numerico_rompe_la_pantalla(string $componente, ?string $abrirFormulario): void
    {
        $campos = $this->camposNumericosEnPantalla($componente, $abrirFormulario);

        // Hay pantallas sin campos numéricos sueltos: sucursales usa un select
        // para el ancho de rollo, y en cotizaciones todos viven dentro de la
        // tabla de bultos (items.N.x), que no son propiedades del componente.
        if ($campos === []) {
            $this->markTestSkipped(class_basename($componente) . ' no tiene campos numéricos sueltos.');
        }

        foreach ($campos as $campo) {
            foreach (self::ENTRADAS_HOSTILES as $entrada) {
                try {
                    $c = Livewire::actingAs($this->admin)->test($componente);

                    if ($abrirFormulario) {
                        $c->call($abrirFormulario);
                    }

                    $c->set($campo, $entrada)->html();
                } catch (\Throwable $e) {
                    $this->fail(sprintf(
                        'Escribir %s en «%s» rompió %s: %s',
                        var_export($entrada, true), $campo, class_basename($componente), $e->getMessage()
                    ));
                }
            }
        }

        $this->assertTrue(true);
    }

    /**
     * Los campos numéricos declarados en la vista del componente.
     *
     * Se lee la plantilla y no el HTML renderizado: muchos campos viven dentro
     * de condicionales —el límite de crédito solo aparece si el cliente es de
     * crédito— y no saldrían en el primer render.
     *
     * @return array<int,string>
     */
    private function camposNumericosEnPantalla(string $componente, ?string $abrirFormulario): array
    {
        $vista = $this->vistaDe($componente);

        $this->assertFileExists($vista,
            'No se encontró la vista de ' . class_basename($componente)
            . '. Si cambió de ubicación, este guardián dejó de mirar.');

        $plantilla = file_get_contents($vista);

        preg_match_all('/<input[^>]*type="number"[^>]*>/', $plantilla, $inputs);

        $campos = [];

        foreach ($inputs[0] as $input) {
            if (preg_match('/wire:model[^=]*="([^"]+)"/', $input, $m)) {
                $campos[] = $m[1];
            }
        }

        // Los índices de un arreglo (items.0.price) no son propiedades sueltas.
        return collect($campos)
            ->unique()
            ->reject(fn ($campo) => str_contains($campo, '.') || str_contains($campo, '{{'))
            ->values()
            ->all();
    }

    /** Convención de Livewire: App\Livewire\Foo\BarBaz -> livewire/foo/bar-baz. */
    private function vistaDe(string $componente): string
    {
        $ruta = str_replace('App\\Livewire\\', '', $componente);
        $partes = array_map(
            fn ($p) => \Illuminate\Support\Str::kebab($p),
            explode('\\', $ruta)
        );

        return resource_path('views/livewire/' . implode('/', $partes) . '.blade.php');
    }
}
