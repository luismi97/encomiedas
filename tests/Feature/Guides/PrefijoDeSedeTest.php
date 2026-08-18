<?php

namespace Tests\Feature\Guides;

use App\Models\Branch;
use App\Models\Invoice;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * El código guía es PREFIJO_ORIGEN-PREFIJO_DESTINO-CONSECUTIVO.
 *
 * Sin prefijo, GuideCodeGenerator no puede armarlo y el observer cae a un
 * ENC-000045 de respaldo. Ese respaldo existe para no perder una encomienda ya
 * recibida, pero convertía un dato de configuración faltante en códigos
 * silenciosamente equivocados: los seeders creaban las sedes sin prefijo, así
 * que una instalación nueva nunca emitía un código con formato de ruta.
 */
class PrefijoDeSedeTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::create([
            'name' => 'Admin', 'username' => 'admin', 'email' => 'admin@t.test',
            'password' => bcrypt('x'), 'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    private function guia(Branch $origen, Branch $destino): Invoice
    {
        return Invoice::create([
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $origen->id, 'delivery_branch_id' => $destino->id,
            'sender_name' => 'Marta', 'recipient_name' => 'José',
            'subtotal' => 3000, 'discount_amount' => 0, 'tax_total' => 0, 'total' => 3000,
            'created_by' => $this->admin()->id,
        ])->fresh();
    }

    /** El arreglo de raíz: una sede no puede quedar sin prefijo. */
    public function test_una_sede_sin_prefijo_lo_recibe_del_nombre(): void
    {
        $sede = Branch::create([
            'name' => 'Limón Centro', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->assertSame('LIM', $sede->prefix);
    }

    public function test_el_prefijo_explicito_manda_sobre_el_derivado(): void
    {
        $sede = Branch::create([
            'name' => 'San José Central', 'prefix' => 'SJ',
            'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->assertSame('SJ', $sede->prefix);
    }

    public function test_el_prefijo_siempre_queda_en_mayusculas(): void
    {
        $sede = Branch::create([
            'name' => 'Heredia', 'prefix' => ' her ',
            'sucursal_code' => '003', 'terminal_code' => '00001', 'is_active' => true,
        ]);

        $this->assertSame('HER', $sede->prefix);
    }

    /** La columna es única: dos nombres parecidos no pueden tumbar la creación. */
    public function test_dos_sedes_con_nombre_parecido_no_chocan(): void
    {
        $a = Branch::create(['name' => 'Cartago Centro', 'sucursal_code' => '004', 'terminal_code' => '00001', 'is_active' => true]);
        $b = Branch::create(['name' => 'Cartago Oriental', 'sucursal_code' => '008', 'terminal_code' => '00001', 'is_active' => true]);

        $this->assertSame('CAR', $a->prefix);
        $this->assertNotSame($a->prefix, $b->prefix);
        $this->assertNotEmpty($b->prefix);
    }

    /** El bug reportado: el código volvió a tener formato de ruta. */
    public function test_el_codigo_guia_lleva_prefijo_de_origen_y_destino(): void
    {
        $sj  = Branch::create(['name' => 'San José', 'prefix' => 'SJ', 'sucursal_code' => '001', 'terminal_code' => '00001', 'is_active' => true]);
        $lim = Branch::create(['name' => 'Limón', 'prefix' => 'LIM', 'sucursal_code' => '006', 'terminal_code' => '00001', 'is_active' => true]);

        $this->assertSame('SJ-LIM-00001', $this->guia($sj, $lim)->code);
        $this->assertSame('SJ-LIM-00002', $this->guia($sj, $lim)->code);
        $this->assertSame('LIM-SJ-00001', $this->guia($lim, $sj)->code);
    }

    /** Aunque nadie haya digitado un prefijo, el código nunca cae al respaldo. */
    public function test_una_sede_creada_sin_prefijo_igual_emite_codigo_de_ruta(): void
    {
        $a = Branch::create(['name' => 'Puntarenas Centro', 'sucursal_code' => '005', 'terminal_code' => '00001', 'is_active' => true]);
        $b = Branch::create(['name' => 'Liberia Centro', 'sucursal_code' => '007', 'terminal_code' => '00001', 'is_active' => true]);

        $codigo = $this->guia($a, $b)->code;

        $this->assertSame('PUN-LIB-00001', $codigo);
        $this->assertStringStartsNotWith('ENC-', $codigo);
    }

    /** Lo que veía el usuario: instalación sembrada = todos los códigos ENC-. */
    public function test_las_sedes_del_seeder_tienen_prefijo(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $sinPrefijo = Branch::whereNull('prefix')->orWhere('prefix', '')->get();

        $this->assertCount(0, $sinPrefijo,
            'Sedes sin prefijo: ' . $sinPrefijo->pluck('name')->join(', '));

        $this->assertSame(0, Invoice::where('code', 'like', 'ENC-%')->count(),
            'Hay guías con el código de respaldo en vez del de ruta.');
    }
}
