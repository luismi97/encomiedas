<?php

namespace Tests\Feature\Ui;

use App\Models\Branch;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use ReflectionClass;
use Tests\TestCase;

/**
 * Vaciar un campo numérico no puede tumbar la pantalla.
 *
 * El navegador manda "" cuando se borra un <input type="number">. Contra una
 * propiedad `float` tipada, Livewire no puede asignarlo y la deja SIN
 * INICIALIZAR: el siguiente acceso dispara __get y el componente muere con
 * «Property [$x] not found on component» —nombrando el campo que se vació, lo
 * que hacía parecer un problema distinto cada vez—.
 *
 * Nulables sí aceptan el vacío. Este test recorre todos los componentes y
 * vacía cada campo numérico, para que ninguno quede fuera.
 */
class CamposNumericosVaciosTest extends TestCase
{
    use RefreshDatabase;

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

    /** @return array<int,array{0:string}> */
    public static function componentes(): array
    {
        return [
            [\App\Livewire\Invoices\InvoiceForm::class],
            [\App\Livewire\Rates\RateIndex::class],
            [\App\Livewire\Taxes\TaxIndex::class],
            [\App\Livewire\Customers\CustomerIndex::class],
            [\App\Livewire\Caja\CajaPanel::class],
            [\App\Livewire\Credito\CreditoPanel::class],
            [\App\Livewire\Branches\BranchIndex::class],
            [\App\Livewire\PackageTypes\PackageTypeIndex::class],
            [\App\Livewire\Settings\CompanySettingsForm::class],
            [\App\Livewire\Quotes\QuoteIndex::class],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('componentes')]
    public function test_vaciar_cualquier_campo_numerico_no_rompe(string $componente): void
    {
        $numericos = $this->camposNumericosDe($componente);

        if ($numericos === []) {
            $this->markTestSkipped("{$componente} no tiene campos numéricos.");
        }

        foreach ($numericos as $campo) {
            try {
                Livewire::actingAs($this->admin)
                    ->test($componente)
                    ->set($campo, '')
                    ->html();
            } catch (\Throwable $e) {
                $this->fail(
                    "Vaciar «{$campo}» rompió " . class_basename($componente) . ': ' . $e->getMessage()
                );
            }
        }

        $this->assertTrue(true);
    }

    /** Ninguna propiedad numérica puede quedar no nulable. */
    #[\PHPUnit\Framework\Attributes\DataProvider('componentes')]
    public function test_los_campos_numericos_aceptan_vacio(string $componente): void
    {
        $rigidas = [];

        foreach ((new ReflectionClass($componente))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $tipo = $prop->getType();

            if (! $tipo instanceof \ReflectionNamedType) {
                continue;
            }

            if (in_array($tipo->getName(), ['float', 'int'], true) && ! $tipo->allowsNull()) {
                $rigidas[] = $prop->getName();
            }
        }

        $this->assertSame([], $rigidas,
            class_basename($componente) . ': estas propiedades revientan si el campo se vacía — '
            . implode(', ', $rigidas));
    }

    /** @return array<int,string> */
    private function camposNumericosDe(string $componente): array
    {
        $campos = [];

        foreach ((new ReflectionClass($componente))->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $tipo = $prop->getType();

            if ($tipo instanceof \ReflectionNamedType && in_array($tipo->getName(), ['float', 'int'], true)) {
                $campos[] = $prop->getName();
            }
        }

        return $campos;
    }
}
