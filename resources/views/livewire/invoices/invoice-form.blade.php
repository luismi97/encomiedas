<div class="max-w-4xl space-y-6">
    @if ($cajaAviso)
        <div class="flex items-start gap-3 p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200">
            <x-icon name="warning" class="w-5 h-5 mt-0.5" />
            <span>{{ $cajaAviso }}</span>
        </div>
    @endif

    <x-flash />

    <form wire:submit="save" class="space-y-6">
        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Ruta</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Sucursal de recogida</label>
                    <select wire:model.live="pickup_branch_id" class="input">
                        <option value="">Seleccione...</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('pickup_branch_id') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Sucursal de entrega</label>
                    <select wire:model.live="delivery_branch_id" class="input">
                        <option value="">Seleccione...</option>
                        @foreach ($branches as $branch)
                            <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                        @endforeach
                    </select>
                    @error('delivery_branch_id') <p class="error-text">{{ $message }}</p> @enderror
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
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Remitente</h2>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 dark:text-gray-400">Cliente registrado</label>
                    <select wire:model.live="sender_customer_id" class="input !py-1.5 text-sm min-w-[220px]">
                        <option value="">— De mostrador —</option>
                        @foreach ($clientes as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="sender_name" class="input"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="sender_phone" class="input"></div>
                <div><label class="label">Identificación</label><input type="text" wire:model="sender_identification" class="input"></div>
            </div>
            @error('sender_name') <p class="error-text">{{ $message }}</p> @enderror
        </div>

        <div class="card space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Receptor</h2>
                <div class="flex items-center gap-2">
                    <label class="text-sm text-gray-500 dark:text-gray-400">Cliente registrado</label>
                    <select wire:model.live="recipient_customer_id" class="input !py-1.5 text-sm min-w-[220px]">
                        <option value="">— De mostrador —</option>
                        @foreach ($clientes as $cliente)
                            <option value="{{ $cliente->id }}">{{ $cliente->displayName() }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="recipient_name" class="input @error('recipient_name') input-error @enderror"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="recipient_phone" class="input"></div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="recipient_email" class="input @error('recipient_email') input-error @enderror"></div>
            </div>
            @error('recipient_name') <p class="error-text">{{ $message }}</p> @enderror
            @error('recipient_email') <p class="error-text">{{ $message }}</p> @enderror

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="wantsInvoice" class="checkbox mt-0.5">
                    <span>
                        <span class="font-medium">Emitir Factura Electrónica</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">
                            Requiere la identificación del receptor. Si lo dejás sin marcar se emite un
                            <strong>Tiquete Electrónico</strong>, que no la necesita.
                        </span>
                    </span>
                </label>

                @if ($wantsInvoice)
                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-4">
                        <div>
                            <label class="label">Tipo de identificación</label>
                            <select wire:model="recipient_identification_type" class="input @error('recipient_identification_type') input-error @enderror">
                                <option value="01">01 - Física</option>
                                <option value="02">02 - Jurídica</option>
                                <option value="03">03 - DIMEX</option>
                                <option value="04">04 - NITE</option>
                            </select>
                            @error('recipient_identification_type') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Identificación</label>
                            <input type="text" wire:model="recipient_identification" inputmode="numeric" maxlength="12"
                                   placeholder="Sin guiones ni espacios"
                                   class="input @error('recipient_identification') input-error @enderror">
                            @error('recipient_identification') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
            </div>
        </div>

        <div class="card space-y-4">
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 pb-4 border-b border-gray-200 dark:border-gray-700">
                <div>
                    <label class="label">Tipo de envío</label>
                    <select wire:model.live="shipment_type" class="input">
                        @foreach (\App\Models\Rate::SHIPMENT_TYPES as $valor => $etiqueta)
                            <option value="{{ $valor }}">{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="label">Valor declarado (₡)</label>
                    <input type="number" step="0.01" wire:model="declared_value" class="input">
                    <p class="text-xs text-gray-500 mt-1">Para efectos de seguro; no entra en el cobro.</p>
                </div>
            </div>

            <div class="flex items-center justify-between">
                <h2 class="text-lg font-semibold">Paquetes</h2>
                <div class="flex flex-wrap gap-2">
                    <x-action-button action="cotizar" variant="secondary" loadingText="Cotizando..." class="!py-2 !px-3 text-sm">
                        <x-icon name="banknotes" class="w-4 h-4" /> Calcular con el tarifario
                    </x-action-button>
                    <button type="button" wire:click="addItem" class="btn-secondary !py-2 !px-3 text-sm"><x-icon name="plus" class="w-4 h-4" /> Agregar paquete</button>
                </div>
            </div>

            <div class="space-y-3">
                @foreach ($items as $index => $item)
                    <div class="grid grid-cols-2 lg:grid-cols-[repeat(18,minmax(0,1fr))] gap-3 items-end border-b border-gray-100 dark:border-gray-700 pb-3">
                        <div class="col-span-2 lg:col-span-3">
                            <label class="label">Tipo de bulto</label>
                            <select wire:model="items.{{ $index }}.package_type_id"
                                    class="input @error('items.'.$index.'.package_type_id') input-error @enderror">
                                @foreach ($tiposDeBulto as $tipo)
                                    <option value="{{ $tipo->id }}">{{ $tipo->name }}</option>
                                @endforeach
                            </select>
                            @error('items.'.$index.'.package_type_id') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-3">
                            <label class="label">Tamaño</label>
                            <select wire:model="items.{{ $index }}.size" class="input @error('items.'.$index.'.size') input-error @enderror">
                                <option value="S">Pequeño</option>
                                <option value="M">Mediano</option>
                                <option value="L">Grande</option>
                                <option value="XL">Extra grande</option>
                            </select>
                            @error('items.'.$index.'.size') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-2">
                            <label class="label">Peso (kg)</label>
                            <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.weight" class="input @error('items.'.$index.'.weight') input-error @enderror">
                            @error('items.'.$index.'.weight') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        {{-- Tres campos en una sola columna quedaban de unos pocos
                             píxeles cada uno: el grupo se lleva tres columnas. --}}
                        <div class="col-span-2 lg:col-span-4">
                            <label class="label">L × A × H (cm)</label>
                            <div class="grid grid-cols-3 gap-1">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.length_cm" placeholder="Largo" class="input input-compacto">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.width_cm" placeholder="Ancho" class="input input-compacto">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.height_cm" placeholder="Alto" class="input input-compacto">
                            </div>
                        </div>
                        <div class="col-span-2 lg:col-span-3">
                            <label class="label">Descripción</label>
                            <input type="text" wire:model="items.{{ $index }}.description" class="input @error('items.'.$index.'.description') input-error @enderror">
                            @error('items.'.$index.'.description') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-2">
                            <label class="label">Precio (₡)</label>
                            <input type="number" step="0.01" wire:model.live="items.{{ $index }}.price" class="input @error('items.'.$index.'.price') input-error @enderror">
                            @error('items.'.$index.'.price') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-1">
                            @if (count($items) > 1)
                                <button type="button" wire:click="removeItem({{ $index }})" class="btn-danger !py-2 !px-3 text-sm w-full">Quitar</button>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
            @error('items') <p class="error-text">{{ $message }}</p> @enderror

            @if ($quote)
                <div class="rounded-lg border {{ $quote['sin_tarifa']
                    ? 'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30'
                    : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/30' }} p-3 text-sm">
                    Peso facturable total: <strong>{{ $quote['peso_total'] }} kg</strong> ·
                    Precio sugerido: <strong>₡{{ number_format($quote['precio_total'], 2) }}</strong>
                    @if ($quote['sin_tarifa'])
                        <div class="mt-1 text-amber-800 dark:text-amber-200">
                            Algún paquete no tiene tarifa para esta ruta y peso: revisá su precio a mano.
                        </div>
                    @else
                        <div class="mt-1 text-gray-600 dark:text-gray-300">
                            Es una sugerencia: podés ajustar cualquier precio antes de guardar.
                        </div>
                    @endif
                </div>
            @endif
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
            {{-- Una sola decisión, porque en el mostrador son excluyentes: o lo
                 paga el remitente ahora, o lo paga quien retira, o va a la
                 cuenta del cliente. De esto depende a qué caja entra la plata. --}}
            <div>
                <label class="label">¿Cómo se paga esta guía?</label>
                <div class="grid gap-3 sm:grid-cols-3 max-w-3xl mt-1">
                    <label class="flex items-start gap-2 rounded-lg border p-3 cursor-pointer transition
                        {{ $cobro === 'prepaid'
                            ? 'border-brand-500 bg-brand-50 dark:bg-brand-900/20'
                            : 'border-gray-300 dark:border-gray-600' }}">
                        <input type="radio" wire:model.live="cobro" value="prepaid" class="mt-1">
                        <span>
                            <span class="font-medium block">Pagado</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                El remitente paga ahora. Entra al arqueo de esta caja.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 rounded-lg border p-3 cursor-pointer transition
                        {{ $cobro === 'collect'
                            ? 'border-amber-500 bg-amber-50 dark:bg-amber-900/20'
                            : 'border-gray-300 dark:border-gray-600' }}">
                        <input type="radio" wire:model.live="cobro" value="collect" class="mt-1">
                        <span>
                            <span class="font-medium block">Por cobrar</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                Paga quien la retira. Se cobra en destino, al entregar.
                            </span>
                        </span>
                    </label>

                    <label class="flex items-start gap-2 rounded-lg border p-3 transition
                        {{ ! $remitenteEsDeCredito ? 'opacity-50 cursor-not-allowed' : 'cursor-pointer' }}
                        {{ $cobro === 'credit'
                            ? 'border-purple-500 bg-purple-50 dark:bg-purple-900/20'
                            : 'border-gray-300 dark:border-gray-600' }}">
                        <input type="radio" wire:model.live="cobro" value="credit" class="mt-1"
                               @disabled(! $remitenteEsDeCredito)>
                        <span>
                            <span class="font-medium block">A crédito</span>
                            <span class="text-xs text-gray-500 dark:text-gray-400">
                                @if ($remitenteEsDeCredito)
                                    Suma al saldo del remitente. Se factura en el corte.
                                @else
                                    Requiere elegir un remitente con convenio.
                                @endif
                            </span>
                        </span>
                    </label>
                </div>
                @error('cobro') <p class="error-text mt-1">{{ $message }}</p> @enderror

                @if ($creditoAviso)
                    <p class="mt-2 text-sm rounded-lg border border-purple-200 dark:border-purple-800
                              bg-purple-50 dark:bg-purple-900/20 text-purple-900 dark:text-purple-100 p-3">
                        {{ $creditoAviso }}
                    </p>
                @endif
            </div>

            <div class="grid gap-4 sm:grid-cols-2 max-w-xl">
                <div>
                    <label class="label">Descuento (₡)</label>
                    <input type="number" step="0.01" wire:model.live="discount_amount" class="input">
                </div>
                <div>
                    <label class="label">Medio de pago</label>
                    <select wire:model="payment_method" class="input" @disabled($cobro === 'credit')>
                        @foreach (\App\Models\Invoice::PAYMENT_METHODS as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('payment_method') <span class="error-text">{{ $message }}</span> @enderror
                </div>
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
            <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando..."><x-icon name="check" class="w-4 h-4" /> Guardar factura</x-action-button>
            <a href="{{ route('invoices.index') }}" class="btn-secondary">Cancelar</a>
        </div>
    </form>
</div>
