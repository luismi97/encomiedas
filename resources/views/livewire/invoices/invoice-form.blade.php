<div class="max-w-4xl space-y-6">
    <form wire:submit="save" class="space-y-6">
        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Ruta</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Sucursal de recogida</label>
                    <select wire:model="pickup_branch_id" class="input">
                        <option value="">Seleccione...</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('pickup_branch_id') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Sucursal de entrega</label>
                    <select wire:model="delivery_branch_id" class="input">
                        <option value="">Seleccione...</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('delivery_branch_id') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Repartidor asignado</label>
                    <select wire:model="assigned_to" class="input">
                        <option value="">— Sin asignar —</option>
                        @foreach ($repartidores as $r)
                            <option value="{{ $r->id }}">{{ $r->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Remitente</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="sender_name" class="input"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="sender_phone" class="input"></div>
                <div><label class="label">Identificación</label><input type="text" wire:model="sender_identification" class="input"></div>
            </div>
            @error('sender_name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Receptor</h2>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="recipient_name" class="input"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="recipient_phone" class="input"></div>
                <div>
                    <label class="label">Tipo ID</label>
                    <select wire:model="recipient_identification_type" class="input">
                        <option value="01">01 - Física</option>
                        <option value="02">02 - Jurídica</option>
                        <option value="03">03 - DIMEX</option>
                        <option value="04">04 - NITE</option>
                    </select>
                </div>
                <div><label class="label">Identificación (para Factura electrónica)</label><input type="text" wire:model="recipient_identification" class="input"></div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="recipient_email" class="input"></div>
            </div>
            @error('recipient_name') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
            <p class="text-sm text-gray-500 dark:text-gray-400">Si no indica identificación, el comprobante se emite como Tiquete Electrónico.</p>
        </div>

        <div class="card space-y-4">
            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Paquetes</h2>
                <button type="button" wire:click="addItem" class="btn-secondary !py-2 !px-3 text-sm">➕ Agregar paquete</button>
            </div>

            <div class="space-y-3">
                @foreach ($items as $index => $item)
                    <div class="grid grid-cols-2 sm:grid-cols-6 gap-3 items-end border-b border-gray-100 dark:border-gray-700 pb-3">
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label">Código de paquete</label>
                            <input type="text" wire:model="items.{{ $index }}.package_code" class="input">
                        </div>
                        <div>
                            <label class="label">Tamaño</label>
                            <select wire:model="items.{{ $index }}.size" class="input">
                                <option value="S">Pequeño</option>
                                <option value="M">Mediano</option>
                                <option value="L">Grande</option>
                                <option value="XL">Extra grande</option>
                            </select>
                        </div>
                        <div>
                            <label class="label">Peso (kg)</label>
                            <input type="number" step="0.01" wire:model="items.{{ $index }}.weight" class="input">
                        </div>
                        <div class="col-span-2 sm:col-span-1">
                            <label class="label">Descripción</label>
                            <input type="text" wire:model="items.{{ $index }}.description" class="input">
                        </div>
                        <div>
                            <label class="label">Precio (₡)</label>
                            <input type="number" step="0.01" wire:model.live="items.{{ $index }}.price" class="input">
                        </div>
                        <div>
                            @if (count($items) > 1)
                                <button type="button" wire:click="removeItem({{ $index }})" class="btn-danger !py-2 !px-3 text-sm w-full">Quitar</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @error('items') <p class="text-red-600 text-sm">{{ $message }}</p> @enderror
        </div>

        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Impuestos y descuento</h2>
            <div class="flex flex-wrap gap-4">
                @foreach ($taxes as $tax)
                    <label class="flex items-center gap-2">
                        <input type="checkbox" wire:model.live="selectedTaxes" value="{{ $tax->id }}" class="rounded">
                        {{ $tax->name }} ({{ $tax->percent }}%)
                    </label>
                @endforeach
            </div>
            <div class="max-w-xs">
                <label class="label">Descuento (₡)</label>
                <input type="number" step="0.01" wire:model.live="discount_amount" class="input">
            </div>
            <div><label class="label">Notas</label><textarea wire:model="notes" class="input" rows="2"></textarea></div>
        </div>

        <div class="card">
            <div class="flex justify-end">
                <div class="w-full sm:w-72 space-y-1 text-base">
                    <div class="flex justify-between"><span>Subtotal</span><span>₡{{ number_format($this->subtotal, 2) }}</span></div>
                    <div class="flex justify-between"><span>Descuento</span><span>-₡{{ number_format($discount_amount, 2) }}</span></div>
                    <div class="flex justify-between"><span>Impuestos</span><span>₡{{ number_format($this->taxTotal, 2) }}</span></div>
                    <div class="flex justify-between text-lg font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
                        <span>Total</span><span>₡{{ number_format($this->total, 2) }}</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex gap-3">
            <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">💾 Guardar factura</x-action-button>
            <a href="{{ route('invoices.index') }}" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
