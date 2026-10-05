{{-- «¿Quiere factura con cédula?» al entregar (ver DatosDeFactura). Espera $guia. --}}
@if ($this->puedePedirFactura($guia))
    <div class="mt-3 rounded-md border border-gray-200 dark:border-gray-700 p-3">
        <label class="flex items-center gap-2 text-sm font-medium cursor-pointer">
            <input type="checkbox" wire:model.live="quiereFactura">
            ¿Quiere factura con cédula?
        </label>
        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
            La guía va como tiquete. Marcalo si quien retira pide Factura Electrónica a su nombre. Los montos no cambian.
        </p>

        @if ($quiereFactura)
            <div class="mt-3">
                @include('livewire.partials.datos-de-factura-campos')
            </div>
        @endif
    </div>
@endif
