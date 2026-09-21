<?php

namespace Tests\Feature\Empresas;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\GuideCodeGenerator;
use App\Support\CompanyContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lo que una empresa ve y lo que no.
 *
 * Es la prueba que justifica todo el cambio: una sola instalación atiende a
 * varios clientes, y si una consulta se olvida de filtrar, un transportista ve
 * los envíos, los clientes y las cédulas de su competencia. Por eso el filtro
 * vive en el ámbito global y no en cada pantalla —y por eso se prueba con DOS
 * empresas: con una sola, todo parece aislado porque no hay con qué mezclarse—.
 */
class AislamientoPorEmpresaTest extends TestCase
{
    use RefreshDatabase;

    private Company $otra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->otra = Company::create([
            'name' => 'Transportes Vecinos',
            'slug' => 'transportes-vecinos',
            'is_active' => true,
        ]);
    }

    /** Guía con lo mínimo para existir, en la empresa que esté en contexto. */
    private function guia(string $codigo, Branch $origen, Branch $destino, User $creador): Invoice
    {
        return Invoice::create([
            'code' => $codigo,
            'status' => Invoice::STATUS_PENDING,
            'pickup_branch_id' => $origen->id,
            'delivery_branch_id' => $destino->id,
            'sender_name' => 'Remitente',
            'recipient_name' => 'Destinatario',
            'total' => 1000,
            'created_by' => $creador->id,
        ]);
    }

    private function sede(string $nombre, string $prefijo): Branch
    {
        return Branch::create([
            'name' => $nombre,
            'prefix' => $prefijo,
            'sucursal_code' => '001',
            'terminal_code' => '00001',
            'is_active' => true,
        ]);
    }

    private function admin(string $correo): User
    {
        return User::create([
            'name' => 'Admin', 'username' => str_replace(['@', '.'], '', $correo),
            'email' => $correo, 'password' => bcrypt('password'),
            'role' => User::ROLE_ADMIN, 'is_active' => true,
        ]);
    }

    public function test_un_administrador_solo_ve_las_guias_de_su_empresa(): void
    {
        $mia = $this->admin('mio@t.test');
        $sedeMia = $this->sede('Mi sede', 'MIA');
        $this->guia('MIA-MIA-00001', $sedeMia, $sedeMia, $mia);

        $ajena = CompanyContext::para($this->otra, function () {
            $admin = $this->admin('ajeno@t.test');
            $sede = $this->sede('Sede ajena', 'AJE');

            return $this->guia('AJE-AJE-00001', $sede, $sede, $admin);
        });

        $this->actingAs($mia);

        $this->assertSame(1, Invoice::count(), 'El conteo se coló a la otra empresa.');
        $this->assertSame('MIA-MIA-00001', Invoice::first()->code);
        $this->assertNull(
            Invoice::find($ajena->id),
            'Pedir por id una guía de otra empresa tiene que dar nada, no la guía.'
        );
    }

    public function test_los_clientes_y_las_sedes_tampoco_se_cruzan(): void
    {
        $mia = $this->admin('mio@t.test');
        $this->sede('Mi sede', 'MIA');
        Customer::create(['name' => 'Cliente propio', 'identification' => '111']);

        CompanyContext::para($this->otra, function () {
            $this->sede('Sede ajena', 'AJE');
            Customer::create(['name' => 'Cliente ajeno', 'identification' => '222']);
        });

        $this->actingAs($mia);

        $this->assertSame(['Mi sede'], Branch::pluck('name')->all());
        $this->assertSame(['Cliente propio'], Customer::pluck('name')->all());
    }

    /**
     * La misma cédula en dos empresas.
     *
     * Un mismo comerciante le compra a dos transportistas: los dos lo tienen
     * registrado, y ninguno tiene por qué enterarse del otro. Con el índice
     * único global que había antes, el segundo en registrarlo no podía.
     */
    public function test_la_misma_cedula_puede_existir_en_dos_empresas(): void
    {
        Customer::create(['name' => 'Ferretería El Clavo', 'identification' => '3101123456']);

        CompanyContext::para($this->otra, function () {
            Customer::create(['name' => 'Ferretería El Clavo', 'identification' => '3101123456']);
        });

        $this->assertSame(2, Customer::withoutGlobalScopes()->where('identification', '3101123456')->count());
    }

    /**
     * Cada empresa numera sus guías desde uno.
     *
     * El consecutivo va impreso en la etiqueta y el cliente lo lee: que la
     * primera guía de un transportista salga con el número 48 porque otro ya
     * emitió 47 sería, como mínimo, una conversación incómoda.
     */
    public function test_el_consecutivo_de_guias_arranca_de_cero_en_cada_empresa(): void
    {
        $generador = app(GuideCodeGenerator::class);

        $sedeMia = $this->sede('Mi sede', 'SJ');
        $primeraMia = $generador->generar($sedeMia, $sedeMia);
        $segundaMia = $generador->generar($sedeMia, $sedeMia);

        $primeraAjena = CompanyContext::para($this->otra, function () use ($generador) {
            // Mismo prefijo a propósito: es el choque que tiene que estar permitido.
            $sede = $this->sede('Sede ajena', 'SJ');

            return $generador->generar($sede, $sede);
        });

        $this->assertSame('SJ-SJ-00001', $primeraMia);
        $this->assertSame('SJ-SJ-00002', $segundaMia);
        $this->assertSame('SJ-SJ-00001', $primeraAjena, 'La segunda empresa heredó el consecutivo de la primera.');
    }

    /** El mismo código de guía puede existir en las dos. */
    public function test_dos_empresas_pueden_tener_la_misma_guia(): void
    {
        $mia = $this->admin('mio@t.test');
        $sedeMia = $this->sede('Mi sede', 'SJ');
        $this->guia('SJ-SJ-00001', $sedeMia, $sedeMia, $mia);

        CompanyContext::para($this->otra, function () {
            $admin = $this->admin('ajeno@t.test');
            $sede = $this->sede('Sede ajena', 'SJ');
            $this->guia('SJ-SJ-00001', $sede, $sede, $admin);
        });

        $this->assertSame(2, Invoice::withoutGlobalScopes()->where('code', 'SJ-SJ-00001')->count());
    }

    /**
     * Lo que se crea queda a nombre de la empresa activa, sin que nadie lo pida.
     *
     * Es la mitad silenciosa del aislamiento: una fila guardada sin company_id
     * no la vuelve a ver nadie, ni su propia empresa.
     */
    public function test_lo_creado_hereda_la_empresa_activa(): void
    {
        $impuesto = CompanyContext::para($this->otra, fn () => Tax::create([
            'name' => 'IVA de la otra', 'percent' => 13, 'hacienda_code' => '08',
        ]));

        $this->assertSame($this->otra->id, $impuesto->company_id);
    }

    /**
     * Sin empresa en contexto no se filtra nada.
     *
     * Es a propósito y sostiene el trabajo de fondo: el worker de la cola y los
     * comandos programados recorren todas las empresas. Si esto cambiara, el
     * envío a Hacienda dejaría de procesar lo de todos menos uno.
     */
    public function test_sin_empresa_en_contexto_se_ve_todo(): void
    {
        $mia = $this->admin('mio@t.test');
        $sedeMia = $this->sede('Mi sede', 'MIA');
        $this->guia('MIA-MIA-00001', $sedeMia, $sedeMia, $mia);

        CompanyContext::para($this->otra, function () {
            $admin = $this->admin('ajeno@t.test');
            $sede = $this->sede('Sede ajena', 'AJE');
            $this->guia('AJE-AJE-00001', $sede, $sede, $admin);
        });

        $todas = CompanyContext::sinAlcance(fn () => Invoice::count());

        $this->assertSame(2, $todas);
    }
}
