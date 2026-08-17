<?php

namespace App\Livewire\Settings;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Services\Hacienda\CabysService;
use App\Services\Hacienda\HaciendaAuth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Component;
use Livewire\WithFileUploads;

class CompanySettingsForm extends Component
{
    use WithFileUploads;

    public bool $enabled = false;
    public string $environment = 'sandbox';
    public string $name = '';
    public string $commercial_name = '';
    public string $identification_type = '02';
    public string $identification_number = '';
    public string $activity_code = '';
    public string $province = '';
    public string $canton = '';
    public string $district = '';
    public string $barrio = '';
    public string $others_signs = '';
    public string $phone_code = '506';
    public string $phone = '';
    public string $email = '';
    public string $atv_username = '';
    public string $atv_password = '';
    public string $certificate_pin = '';
    public string $default_cabys_code = '';

    /** @var mixed */
    public $certificate;

    public bool $hasCertificate = false;

    /**
     * Codigos de Hacienda por sucursal. Viven aqui ademas de en la pantalla de
     * Sucursales porque son parte de la identidad del emisor: el par
     * sucursal+terminal viaja dentro del consecutivo de cada comprobante.
     *
     * @var array<int,array{id:int,name:string,sucursal_code:string,terminal_code:string,locked:bool}>
     */
    public array $branches = [];

    /**
     * Campos cifrados que ya no se pueden descifrar porque el APP_KEY cambió.
     * Sin avisarlo, el sintoma es que todo comprobante falla sin razon visible.
     *
     * @var array<int,string>
     */
    public array $unreadableFields = [];

    public ?string $connectionTestStatus = null;
    public ?string $connectionTestMessage = null;

    /** Buscador CABYS: escribe sobre default_cabys_code. */
    public string $cabysTerm = '';
    /** @var array<int,array{codigo:string,descripcion:string,impuesto:float}> */
    public array $cabysResults = [];
    public ?string $cabysMessage = null;

    public function mount(): void
    {
        $settings = CompanySetting::instance();

        $this->enabled = (bool) $settings->enabled;
        $this->environment = $settings->environment ?: 'sandbox';
        $this->name = (string) $settings->name;
        $this->commercial_name = (string) $settings->commercial_name;
        $this->identification_type = $settings->identification_type ?: '02';
        $this->identification_number = (string) $settings->identification_number;
        $this->activity_code = (string) $settings->activity_code;
        $this->province = (string) $settings->province;
        $this->canton = (string) $settings->canton;
        $this->district = (string) $settings->district;
        $this->barrio = (string) $settings->barrio;
        $this->others_signs = (string) $settings->others_signs;
        $this->phone_code = (string) ($settings->phone_code ?: '506');
        $this->phone = (string) $settings->phone;
        $this->email = (string) $settings->email;
        $this->atv_username = (string) $settings->atv_username;
        $this->default_cabys_code = (string) $settings->default_cabys_code;
        $this->hasCertificate = filled($settings->certificate_path);
        $this->unreadableFields = $settings->undecryptableFields();
        $this->loadBranches();
    }

    private function loadBranches(): void
    {
        $this->branches = Branch::orderBy('name')->get()->map(fn (Branch $b) => [
            'id'            => $b->id,
            'name'          => $b->name,
            'sucursal_code' => $b->sucursal_code ?: '001',
            'terminal_code' => $b->terminal_code ?: '00001',
            // Ya emitio comprobantes: cambiar el par desalinea el consecutivo.
            'locked'        => $b->hasHaciendaHistory(),
        ])->all();
    }

    protected function rules(): array
    {
        return [
            'environment' => 'required|in:sandbox,prod',
            'name' => 'required|string|max:150',
            'commercial_name' => 'nullable|string|max:100',
            'identification_type' => 'required|in:01,02,03,04',
            'identification_number' => 'required|string|max:20',
            // Hacienda rechaza con -408 si no es un codigo del Registro Unico
            // Tributario. Los formatos validos son 6 digitos o 4+punto+1.
            'activity_code' => ['required', 'regex:/^(?:\d{6}|\d{4}\.\d)$/'],
            // Hacienda las valida contra lo inscrito en Tributación (aviso -37)
            // y el XML las lleva con ancho fijo.
            'province' => ['required', 'regex:/^[1-7]$/'],
            'canton' => ['required', 'regex:/^\d{2}$/'],
            'district' => ['required', 'regex:/^\d{2}$/'],
            'barrio' => 'nullable|string|max:100',
            'others_signs' => 'nullable|string|max:250',
            'phone_code' => ['required', 'regex:/^\d{1,3}$/'],
            'phone' => 'nullable|string|max:30',
            'email' => 'nullable|email',
            'atv_username' => 'nullable|string|max:150',
            'atv_password' => 'nullable|string|max:150',
            'certificate' => 'nullable|file|max:2048',
            'certificate_pin' => 'nullable|string|max:50',
            'default_cabys_code' => 'nullable|string|max:13',
            'branches.*.sucursal_code' => ['required', 'regex:/^\d{3}$/'],
            'branches.*.terminal_code' => ['required', 'regex:/^\d{5}$/'],
        ];
    }

    protected function messages(): array
    {
        return [
            'phone_code.regex' => 'El código de país son 1 a 3 dígitos (Costa Rica: 506).',
            'activity_code.required' => 'El código de actividad económica es obligatorio: Hacienda rechaza el comprobante sin él.',
            'activity_code.regex' => 'El código de actividad debe ser el del Registro Único Tributario: 6 dígitos (ej. 532000) o 4 dígitos con decimal (ej. 5320.0).',
            'province.regex' => 'La provincia es 1 dígito del 1 al 7.',
            'canton.regex' => 'El cantón son 2 dígitos (ej. 08).',
            'district.regex' => 'El distrito son 2 dígitos (ej. 02).',
            'branches.*.sucursal_code.required' => 'El código de sucursal es obligatorio.',
            'branches.*.sucursal_code.regex' => 'El código de sucursal son 3 dígitos (ej. 001).',
            'branches.*.terminal_code.required' => 'El código de terminal es obligatorio.',
            'branches.*.terminal_code.regex' => 'El código de terminal son 5 dígitos (ej. 00001).',
        ];
    }

    /**
     * Dos sucursales no pueden compartir sucursal+terminal.
     *
     * Ese par va dentro del consecutivo y el contador es por sucursal: si se
     * repiten, ambas numeran 1, 2, 3... por su lado y la segunda choca contra
     * Hacienda con "el comprobante ya existe".
     */
    private function validateUniqueBranchCodes(): void
    {
        $vistos = [];
        $mensajes = [];

        foreach ($this->branches as $i => $b) {
            $clave = str_pad($b['sucursal_code'], 3, '0', STR_PAD_LEFT)
                . '-' . str_pad($b['terminal_code'], 5, '0', STR_PAD_LEFT);

            if (isset($vistos[$clave])) {
                [$primerIndice, $primerNombre] = $vistos[$clave];

                // Se marcan las DOS filas del choque: quien edito una sucursal
                // espera ver el error ahi, no en la otra mitad del par.
                $mensajes['branches.' . $i . '.terminal_code'] =
                    'Esta combinación ya la usa «' . $primerNombre . '». Cada sucursal necesita la suya, '
                    . 'o Hacienda rechaza los comprobantes por consecutivo repetido.';
                $mensajes['branches.' . $primerIndice . '.terminal_code'] =
                    'Esta combinación ya la usa «' . ($b['name'] ?? 'otra sucursal') . '». Cada sucursal necesita la suya, '
                    . 'o Hacienda rechaza los comprobantes por consecutivo repetido.';
                continue;
            }

            $vistos[$clave] = [$i, $b['name'] ?? ('sucursal ' . ($i + 1))];
        }

        if ($mensajes) {
            throw ValidationException::withMessages($mensajes);
        }
    }

    /**
     * Verifica las credenciales ATV contra Hacienda pidiendo un token.
     * Sin esto el primer aviso de que estan mal es un comprobante fallido.
     */
    public function testConnection(HaciendaAuth $auth): void
    {
        $settings = CompanySetting::instance();

        if (!filled($settings->decryptedOrNull('atv_username')) || !filled($settings->decryptedOrNull('atv_password'))) {
            $this->connectionTestStatus = 'warning';
            $this->connectionTestMessage = 'Guardá primero el usuario y la contraseña de ATV antes de probar la conexión.';

            return;
        }

        try {
            $auth->fresh($settings);
            $this->connectionTestStatus = 'success';
            $this->connectionTestMessage = 'Conexión con Hacienda exitosa (' . $settings->effectiveEnvironment()
                . '): credenciales ATV válidas, token obtenido.';
        } catch (\Throwable $e) {
            $this->connectionTestStatus = 'error';
            $this->connectionTestMessage = 'Falló la conexión: ' . $e->getMessage();
        }
    }

    public function save(): void
    {
        $data = $this->validate();
        $this->validateUniqueBranchCodes();

        $settings = CompanySetting::instance();

        if ($this->certificate) {
            $path = $this->certificate->storeAs('certificados', $settings->id . '.p12', 'hacienda');
            $settings->certificate_path = $path;
            $this->hasCertificate = true;
        }

        $settings->fill([
            'enabled' => $this->enabled,
            'environment' => $data['environment'],
            'name' => $data['name'],
            'commercial_name' => $data['commercial_name'],
            'identification_type' => $data['identification_type'],
            'identification_number' => $data['identification_number'],
            'activity_code' => $data['activity_code'],
            'province' => $data['province'],
            'canton' => $data['canton'],
            'district' => $data['district'],
            'barrio' => $data['barrio'],
            'others_signs' => $data['others_signs'],
            'phone_code' => $data['phone_code'],
            'phone' => $data['phone'],
            'email' => $data['email'],
            'default_cabys_code' => $data['default_cabys_code'],
        ]);

        if (filled($this->atv_username)) {
            $settings->atv_username = $this->atv_username;
        }
        if (filled($this->atv_password)) {
            $settings->atv_password = $this->atv_password;
        }
        if (filled($this->certificate_pin)) {
            $settings->certificate_pin = $this->certificate_pin;
        }

        $settings->save();
        $this->saveBranchCodes();

        $this->certificate = null;
        $this->atv_password = '';
        $this->certificate_pin = '';

        session()->flash('success', 'Configuración de la empresa guardada.');
    }

    public function searchCabys(CabysService $cabys): void
    {
        $this->cabysResults = [];
        $this->cabysMessage = null;

        $term = trim($this->cabysTerm);

        if (mb_strlen($term) < 3) {
            $this->cabysMessage = 'Escribí al menos 3 caracteres, o el código completo de 13 dígitos.';

            return;
        }

        $results = $cabys->search($term);

        // null = no se pudo consultar (el API de Hacienda bloquea clientes
        // automatizados a ratos); [] = se consulto y no hay coincidencias.
        if ($results === null) {
            $this->cabysMessage = 'No se pudo consultar el catálogo de Hacienda en este momento. '
                . 'Podés digitar el código manualmente.';

            return;
        }

        if ($results === []) {
            $this->cabysMessage = 'Sin resultados para «' . $term . '».';

            return;
        }

        $this->cabysResults = $results;
    }

    public function useCabys(string $code): void
    {
        $this->default_cabys_code = $code;
        $this->cabysResults = [];
        $this->cabysTerm = '';
        $this->cabysMessage = 'Código CABYS ' . $code . ' seleccionado. Acordate de guardar.';
    }

    /** Solo toca las sucursales sin historial: las demas quedan congeladas. */
    private function saveBranchCodes(): void
    {
        foreach ($this->branches as $b) {
            $branch = Branch::find($b['id']);

            if (!$branch || $branch->hasHaciendaHistory()) {
                continue;
            }

            $branch->update([
                'sucursal_code' => str_pad($b['sucursal_code'], 3, '0', STR_PAD_LEFT),
                'terminal_code' => str_pad($b['terminal_code'], 5, '0', STR_PAD_LEFT),
            ]);
        }

        $this->loadBranches();
    }

    public function render()
    {
        return view('livewire.settings.company-settings-form')
            ->layout('layouts.app', ['title' => 'Configuración de la empresa']);
    }
}
