<?php

namespace App\Livewire\Dispatches;

use App\Rules\DeLaEmpresa;
use App\Models\Branch;
use App\Models\Dispatch;
use App\Models\Invoice;
use App\Models\ShippingRoute;
use App\Scopes\BranchScope;
use App\Models\User;
use App\Services\DispatchService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use App\Livewire\Concerns\ScrollInfinito;
use Livewire\Component;
use RuntimeException;

class DispatchIndex extends Component
{
    use ScrollInfinito;

    public string $filterStatus = '';

    public bool $showForm = false;
    /** Atajo: elegir la ruta pone origen y destino del cierre. */
    public $shipping_route_id = null;
    public $origin_branch_id = null;
    public $destination_branch_id = null;
    public string $driver_name = '';
    public $driver_user_id = null;
    public string $vehicle_plate = '';
    public string $notes = '';

    /** Manifiesto abierto en el panel de detalle. */
    public $openId = null;
    public string $scanCode = '';

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    /**
     * Avisa al navegador cómo salió el escaneo, para que suene.
     *
     * Se emite desde acá y no al detectar el código: un pitido tiene que
     * significar «la guía quedó marcada». Si sonara con cada lectura, un
     * código de otra ruta también pitaría y el operario dejaría de fiarse.
     */
    private function sonarEscaneo(bool $ok): void
    {
        $this->dispatch('scan-resultado', ok: $ok);
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    protected function rules(): array
    {
        return [
            'origin_branch_id' => ['required', DeLaEmpresa::en('branches')],
            'destination_branch_id' => ['required', 'different:origin_branch_id', DeLaEmpresa::en('branches')],
            'driver_name' => 'nullable|string|max:150',
            'driver_user_id' => ['nullable', DeLaEmpresa::en('users')],
            'vehicle_plate' => 'nullable|string|max:20',
            'notes' => 'nullable|string|max:1000',
        ];
    }

    protected function messages(): array
    {
        return [
            'destination_branch_id.different' => 'El destino tiene que ser una sede distinta del origen.',
            'origin_branch_id.required' => 'Elegí la sede de origen.',
            'destination_branch_id.required' => 'Elegí la sede de destino.',
        ];
    }

    /**
     * Elegir un repartidor rellena el nombre del manifiesto.
     *
     * Son dos campos y nadie sabía cuál mandaba: se elegía un chofer del
     * desplegable y la columna seguía mostrando lo que hubiera en el texto
     * libre. Ahora los dos coinciden salvo que alguien cambie el nombre a
     * propósito, que es el caso de un transporte externo.
     */
    public function updatedDriverUserId($value): void
    {
        if (! $value) {
            return;
        }

        if ($chofer = User::find($value)) {
            $this->driver_name = $chofer->name;
        }
    }

    /** Elegir la ruta arma la cabecera del cierre: origen y destino de un toque. */
    public function updatedShippingRouteId($value): void
    {
        if (! $value || ! $ruta = ShippingRoute::find($value)) {
            return;
        }

        $this->origin_branch_id = $ruta->origin_branch_id;
        $this->destination_branch_id = $ruta->destination_branch_id;
    }

    public function create(): void
    {
        $this->feedback = null;
        $this->reset(['shipping_route_id', 'origin_branch_id', 'destination_branch_id', 'driver_name', 'driver_user_id', 'vehicle_plate', 'notes']);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(): void
    {
        // La ruta es solo el atajo que rellenó los dos campos: el cierre guarda
        // las sedes, que es lo que el manifiesto dice desde siempre.
        $data = $this->validate();

        $manifiesto = $this->crearConCodigo($data + ['created_by' => auth()->id()]);

        $this->showForm = false;
        $this->openId = $manifiesto->id;
        $this->notify('success', "Cierre {$manifiesto->code} creado. Agregale las guías que salen en este viaje.");
    }

    /**
     * Crea el cierre con su CIE-000001, sin repetirlo.
     *
     * El número se calcula y el cierre se guarda en la MISMA transacción: antes
     * el candado se soltaba al calcular, y dos cierres creados en el mismo
     * instante salían con el mismo código y el segundo reventaba contra el
     * índice único. Y aun así se reintenta si choca: el primer cierre de una
     * empresa no tiene filas que bloquear, y ahí el candado no protege nada.
     */
    private function crearConCodigo(array $datos): Dispatch
    {
        for ($intento = 1; ; $intento++) {
            try {
                return DB::transaction(function () use ($datos) {
                    // Sobre TODOS los cierres de la empresa, no los que ve el
                    // usuario: el filtro por sede le esconde al cajero los de
                    // otras sucursales, y con eso el de una sede sin cierres
                    // calculaba CIE-000001 aunque ya existiera en otra.
                    $numero = ($this->cierresDeLaEmpresa()->lockForUpdate()->max('id') ?? 0) + 1;

                    // Un código que ya existe —cargado a mano, o de una
                    // numeración anterior— se salta en vez de chocar.
                    while ($this->cierresDeLaEmpresa()->where('code', $this->codigoCierre($numero))->exists()) {
                        $numero++;
                    }

                    return Dispatch::create($datos + ['code' => $this->codigoCierre($numero)]);
                });
            } catch (UniqueConstraintViolationException $e) {
                if ($intento >= 3) {
                    throw $e;
                }
            }
        }
    }

    /** El índice único es por empresa: la numeración también. */
    private function cierresDeLaEmpresa(): \Illuminate\Database\Eloquent\Builder
    {
        return Dispatch::withoutGlobalScope(BranchScope::class);
    }

    private function codigoCierre(int $numero): string
    {
        return 'CIE-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
    }

    public function open(int $id): void
    {
        $this->feedback = null;
        $this->openId = $id;
        $this->scanCode = '';
    }

    public function close(): void
    {
        $this->openId = null;
    }

    public function agregar(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->agregarGuia($this->manifiesto(), Invoice::findOrFail($invoiceId));
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function quitar(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->quitarGuia($this->manifiesto(), Invoice::findOrFail($invoiceId));
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function despachar(DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $manifiesto = $servicio->despachar($this->manifiesto(), auth()->user());
            $this->notify('success', "Cierre {$manifiesto->code} despachado. Sus guías quedaron en «Enviado».");
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    /** Recibe por código de guía: es lo que escribe el lector de QR. */
    public function recibirPorCodigo(DispatchService $servicio): void
    {
        $this->feedback = null;
        $codigo = trim($this->scanCode);

        if ($codigo === '') {
            return;
        }

        $guia = Invoice::where('code', $codigo)->first();

        if (! $guia) {
            $this->notify('error', "No existe ninguna guía con el código «{$codigo}».");
            $this->sonarEscaneo(false);
            $this->scanCode = '';

            return;
        }

        // Ya marcada: el servicio la ignora en silencio, pero sonar a éxito
        // haría creer al operario que marcó una caja distinta.
        $yaRecibida = \App\Models\DispatchGuide::where('dispatch_id', $this->openId)
            ->where('invoice_id', $guia->id)
            ->whereNotNull('received_at')
            ->exists();

        if ($yaRecibida) {
            $this->notify('error', "La guía {$guia->code} ya estaba marcada como recibida.");
            $this->sonarEscaneo(false);
            $this->scanCode = '';

            return;
        }

        try {
            $servicio->recibirGuia($this->manifiesto(), $guia, auth()->user(), 'scan');
            $this->notify('success', "Guía {$guia->code} recibida.");
            $this->sonarEscaneo(true);
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
            $this->sonarEscaneo(false);
        }

        $this->scanCode = '';
    }

    public function recibir(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $servicio->recibirGuia($this->manifiesto(), Invoice::findOrFail($invoiceId), auth()->user());
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    /**
     * El faltante apareció. Va aparte de recibir(): aquella exige un cierre en
     * ruta, y este ya se cerró —es justo el caso que dejaba la guía varada.
     */
    public function recibirFaltante(int $invoiceId, DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $recuperada = $servicio->recibirFaltante($this->manifiesto(), Invoice::findOrFail($invoiceId), auth()->user());

            $this->notify('success', $recuperada
                ? 'Guía recibida en destino y extravío resuelto. '
                    . 'El cierre conserva la marca del faltante: eso pasó en ese viaje.'
                : 'Esta guía ya había aparecido: está en destino desde antes.');
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());
        }
    }

    public function cerrarRecepcion(DispatchService $servicio): void
    {
        $this->feedback = null;

        try {
            $resumen = $servicio->cerrarRecepcion($this->manifiesto(), auth()->user());
        } catch (RuntimeException $e) {
            $this->notify('error', $e->getMessage());

            return;
        }

        if ($resumen['faltantes']) {
            $this->notify('error', 'Recepción cerrada con ' . count($resumen['faltantes'])
                . ' faltante(s): ' . implode(', ', $resumen['faltantes'])
                . '. Cada uno quedó con una incidencia de extravío abierta en su guía.');

            return;
        }

        $this->notify('success', "Recepción cerrada: llegaron las {$resumen['recibidas']} guías del cierre, sin faltantes.");
    }

    private function manifiesto(): Dispatch
    {
        return Dispatch::with(['lines.invoice', 'guides.items', 'originBranch', 'destinationBranch', 'driver'])
            ->findOrFail($this->openId);
    }

    public function updatedFilterStatus(): void
    {
        $this->reiniciarScroll();
    }

    public function render(DispatchService $servicio)
    {
        $abierto = $this->openId ? $this->manifiesto() : null;
        $tanda = $this->tanda(Dispatch::with(['originBranch', 'destinationBranch', 'driver'])
            ->when($this->filterStatus !== '', fn ($q) => $q->where('status', $this->filterStatus))
            ->withCount('lines')
            ->latest());

        return view('livewire.dispatches.dispatch-index', [
            'abierto'     => $abierto,
            'disponibles' => $abierto?->estaAbierto() ? $servicio->disponiblesPara($abierto) : collect(),
            'dispatches'  => $tanda['items'],
            'scroll'      => $tanda,
            'branches'    => Branch::where('is_active', true)->orderBy('name')->get(['id', 'name', 'prefix']),
            // Las de la misma sede no: ese paquete no viaja, y el cierre exige
            // dos sedes distintas.
            'rutas'       => ShippingRoute::active()->with(['originBranch', 'destinationBranch'])
                ->whereColumn('origin_branch_id', '!=', 'destination_branch_id')
                ->orderBy('name')->get(),
            'choferes'    => User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ])->layout('layouts.app', ['title' => 'Cierres de envío']);
    }
}
