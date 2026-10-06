{{-- Campos de a quién se factura (ver DatosDeFactura). La cédula va primero:
     al salir del campo se consulta Hacienda y se completa el resto. --}}
<div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
    <div class="sm:col-span-2">
        <x-customer-picker
            model="facturaClienteId"
            search="facturaClienteBusqueda"
            label="Cliente registrado (opcional)"
            :resultados="$this->clientesParaFactura" />
    </div>
    <div>
        <label class="label">Tipo de identificación</label>
        <select wire:model="facturaTipoId" class="input">
            @foreach (\App\Models\Customer::IDENTIFICATION_TYPES as $codigo => $tipo)
                <option value="{{ $codigo }}">{{ $codigo }} - {{ $tipo }}</option>
            @endforeach
        </select>
        @error('facturaTipoId') <p class="error-text">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label">Número</label>
        <div class="flex gap-2">
            <input type="text" wire:model.blur="facturaId" inputmode="numeric" maxlength="20"
                   placeholder="Sin guiones ni espacios" class="input @error('facturaId') input-error @enderror">
            <button type="button" wire:click="buscarFacturaEnHacienda" wire:loading.attr="disabled"
                    wire:target="buscarFacturaEnHacienda,facturaId"
                    class="btn-secondary !py-2 !px-3 text-sm whitespace-nowrap">
                <span wire:loading.remove wire:target="buscarFacturaEnHacienda,facturaId">Buscar</span>
                <span wire:loading wire:target="buscarFacturaEnHacienda,facturaId">Buscando…</span>
            </button>
        </div>
        @error('facturaId') <p class="error-text">{{ $message }}</p> @enderror
    </div>
    @if ($avisoHacienda)
        <p class="sm:col-span-2 text-sm text-amber-700 dark:text-amber-300">{{ $avisoHacienda }}</p>
    @endif
    <div class="sm:col-span-2">
        <label class="label">Nombre o razón social</label>
        <input type="text" wire:model="facturaNombre" class="input @error('facturaNombre') input-error @enderror">
        @error('facturaNombre') <p class="error-text">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label">Actividad económica <span class="text-gray-400 font-normal">(opcional)</span></label>
        @if ($actividadesHacienda)
            <select wire:model="facturaActividad" class="input">
                <option value="">Sin actividad</option>
                @foreach ($actividadesHacienda as $actividad)
                    <option value="{{ $actividad['code'] }}">{{ $actividad['code'] }} · {{ \Illuminate\Support\Str::limit($actividad['description'], 45) }}</option>
                @endforeach
            </select>
        @else
            <input type="text" wire:model="facturaActividad" maxlength="7" placeholder="Ej. 492300" class="input @error('facturaActividad') input-error @enderror">
        @endif
        @error('facturaActividad') <p class="error-text">{{ $message }}</p> @enderror
    </div>
    <div>
        <label class="label">Correo <span class="text-gray-400 font-normal">(para enviarle la factura)</span></label>
        <input type="email" wire:model="facturaEmail" class="input @error('facturaEmail') input-error @enderror">
        @error('facturaEmail') <p class="error-text">{{ $message }}</p> @enderror
    </div>
</div>
