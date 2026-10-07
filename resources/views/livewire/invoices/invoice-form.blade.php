<div class="max-w-4xl space-y-6">
    
    <x-flash />

    @if ($invoice)
        {{-- Editar una guía ya creada toca cosas que siguieron su curso: se
             avisa antes y no después. --}}
        <div class="card border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/20 text-sm text-amber-900 dark:text-amber-100" data-test="aviso-edicion">
            <p class="font-semibold">Editando la guía {{ $invoice->code }}</p>
            <ul class="list-disc list-inside mt-1 space-y-0.5">
                <li>El código de la guía no cambia aunque cambies las sedes: ya está impreso en la etiqueta.</li>
                @if ($invoice->fueCortada())
                    <li>Está en un estado de cuenta: si cambia el monto, el estado de cuenta se recalcula.</li>
                @endif
                @if ($invoice->electronicInvoice && ! in_array($invoice->electronicInvoice->status, ['pending', 'rejected'], true))
                    <li>Su comprobante electrónico ya está en Hacienda: lo declarado no cambia. Para corregirlo, emití una nota desde la guía.</li>
                @endif
                <li>El estado se corrige desde la guía, con «Corregir estado».</li>
            </ul>
        </div>
    @endif

    {{-- ¿Este equipo puede seguir recibiendo encomiendas si se cae el
         internet? Lo pinta el watchdog (offline/watchdog.blade.php). --}}
    @if (! $invoice && \App\Support\ModoOffline::habilitado())
        <div data-offline-status wire:ignore class="flex items-center gap-2 text-sm">
            <span data-listo class="hidden inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-300">
                <span class="w-1.5 h-1.5 rounded-full bg-green-500"></span> Listo para trabajar sin conexión
            </span>
            <span data-preparando class="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 font-medium bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span> Preparando el modo sin conexión…
            </span>
        </div>
    @endif

    <form wire:submit="save" class="space-y-6">
        <div class="card space-y-4">
            <h2 class="text-lg font-semibold">Ruta</h2>

            @if ($rutas->isNotEmpty())
                <div data-ayuda="guia-ruta">
                    <label class="label flex items-center gap-2">
                        Ruta predefinida
                        <x-ayuda>Elegí la ruta y se rellenan las dos sucursales de un solo toque. Si corregís una sucursal a mano, la ruta se suelta sola: la guía ya no va por ahí.</x-ayuda>
                    </label>
                    <select wire:model.live="shipping_route_id" class="input" data-test="ruta-predefinida">
                        <option value="">— Sin ruta: elegir las sucursales a mano —</option>
                        @foreach ($rutas as $ruta)
                            <option value="{{ $ruta->id }}">{{ $ruta->etiqueta() }}</option>
                        @endforeach
                    </select>
                    @if ($rutaElegida?->transitoLabel())
                        <p class="text-xs text-gray-500 mt-1" data-test="llegada-estimada">
                            Tránsito de {{ $rutaElegida->transitoLabel() }}:
                            llega aproximadamente el {{ $rutaElegida->llegadaEstimadaDesde()->format('d/m/Y') }}.
                        </p>
                    @endif
                    @error('shipping_route_id') <p class="error-text">{{ $message }}</p> @enderror
                </div>
            @endif

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Sucursal de recogida</label>
                    <select wire:model.live="pickup_branch_id" class="input">
                        <option value="">Seleccione...</option>
                        @foreach ($sedesDeOrigen as $branch)
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
                <div class="min-w-[280px]">
                    <x-customer-picker
                        model="sender_customer_id"
                        search="senderSearch"
                        label="Cliente registrado (opcional)"
                        :elegido="$remitenteElegido"
                        :resultados="$resultadosRemitente" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="sender_name" class="input"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="sender_phone" class="input"></div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="sender_email" class="input @error('sender_email') input-error @enderror"></div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Tipo de identificación</label>
                    <select wire:model="sender_identification_type" class="input @error('sender_identification_type') input-error @enderror">
                        <option value="01">01 - Física</option>
                        <option value="02">02 - Jurídica</option>
                        <option value="03">03 - DIMEX</option>
                        <option value="04">04 - NITE</option>
                    </select>
                </div>
                <div>
                    <label class="label">Identificación</label>
                    <input type="text" wire:model.blur="sender_identification" class="input @error('sender_identification') input-error @enderror">
                    @error('sender_identification') <p class="error-text">{{ $message }}</p> @enderror
                </div>
            </div>
            @error('sender_name') <p class="error-text">{{ $message }}</p> @enderror
            @error('sender_email') <p class="error-text">{{ $message }}</p> @enderror
        </div>

        <div class="card space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Receptor</h2>
                <div class="min-w-[280px]">
                    <x-customer-picker
                        model="recipient_customer_id"
                        search="recipientSearch"
                        label="Cliente registrado (opcional)"
                        :elegido="$destinatarioElegido"
                        :resultados="$resultadosDestinatario" />
                </div>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div><label class="label">Nombre</label><input type="text" wire:model="recipient_name" class="input @error('recipient_name') input-error @enderror"></div>
                <div><label class="label">Teléfono</label><input type="text" wire:model="recipient_phone" class="input"></div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="recipient_email" class="input @error('recipient_email') input-error @enderror"></div>
            </div>
            @error('recipient_name') <p class="error-text">{{ $message }}</p> @enderror
            @error('recipient_email') <p class="error-text">{{ $message }}</p> @enderror

            {{-- Siempre a la vista: sirve para verificar a quien retira aunque
                 no se emita factura. Solo la factura la vuelve obligatoria. --}}
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
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
                    <label class="label">Identificación @unless ($wantsInvoice && $bill_to === \App\Models\Invoice::BILL_TO_RECIPIENT)<span class="text-gray-400 font-normal">(opcional)</span>@endunless</label>
                    <input type="text" wire:model.blur="recipient_identification" inputmode="numeric" maxlength="20"
                           placeholder="Sin guiones ni espacios"
                           class="input @error('recipient_identification') input-error @enderror">
                    @error('recipient_identification') <p class="error-text">{{ $message }}</p> @enderror
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="wantsInvoice" class="checkbox mt-0.5">
                    <span>
                        <span class="font-medium">Emitir Factura Electrónica</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">
                            Requiere la identificación de a quién se factura. Si lo dejás sin marcar se emite un
                            <strong>Tiquete Electrónico</strong>, que no la necesita.
                        </span>
                    </span>
                </label>

                @if ($wantsInvoice)
                    <div class="mt-4 space-y-4">
                        <div>
                            <span class="label">Facturar a</span>
                            <div class="flex flex-wrap gap-4">
                                @foreach (\App\Models\Invoice::BILL_TO as $valor => $etiqueta)
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="radio" wire:model.live="bill_to" value="{{ $valor }}">
                                        <span>{{ $etiqueta }}</span>
                                    </label>
                                @endforeach
                            </div>
                            <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">
                                @if ($bill_to === \App\Models\Invoice::BILL_TO_SENDER)
                                    Se usan la identificación y el correo del remitente.
                                @elseif ($bill_to === \App\Models\Invoice::BILL_TO_RECIPIENT)
                                    Se usan la identificación y el correo del destinatario.
                                @endif
                            </p>
                        </div>

                        @if ($bill_to === \App\Models\Invoice::BILL_TO_OTHER)
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="label">Nombre o razón social</label>
                                    <input type="text" wire:model="billing_name" class="input @error('billing_name') input-error @enderror">
                                    @error('billing_name') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="label">Correo electrónico</label>
                                    <input type="email" wire:model="billing_email" class="input @error('billing_email') input-error @enderror">
                                    @error('billing_email') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                                <div>
                                    <label class="label">Tipo de identificación</label>
                                    <select wire:model="billing_identification_type" class="input @error('billing_identification_type') input-error @enderror">
                                        <option value="01">01 - Física</option>
                                        <option value="02">02 - Jurídica</option>
                                        <option value="03">03 - DIMEX</option>
                                        <option value="04">04 - NITE</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="label">Identificación</label>
                                    <input type="text" wire:model.blur="billing_identification" inputmode="numeric" maxlength="20"
                                           placeholder="Sin guiones ni espacios"
                                           class="input @error('billing_identification') input-error @enderror">
                                    @error('billing_identification') <p class="error-text">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        @endif

                        {{-- Al salir del campo de cédula de a quién se factura se consulta
                             Hacienda; el botón repite la consulta a mano. --}}
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4 items-end">
                            <div>
                                <label class="label">Actividad económica del receptor <span class="text-gray-400 font-normal">(opcional)</span></label>
                                @if ($actividadesHacienda)
                                    <select wire:model="billing_activity_code" class="input">
                                        <option value="">Sin actividad</option>
                                        @foreach ($actividadesHacienda as $actividad)
                                            <option value="{{ $actividad['code'] }}">{{ $actividad['code'] }} · {{ \Illuminate\Support\Str::limit($actividad['description'], 50) }}</option>
                                        @endforeach
                                    </select>
                                @else
                                    <input type="text" wire:model="billing_activity_code" maxlength="7" placeholder="Ej. 492300"
                                           class="input @error('billing_activity_code') input-error @enderror">
                                @endif
                                @error('billing_activity_code') <p class="error-text">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <button type="button" wire:click="buscarEnHacienda" wire:loading.attr="disabled" wire:target="buscarEnHacienda"
                                        class="btn-secondary !py-2 !px-3 text-sm">
                                    <span wire:loading.remove wire:target="buscarEnHacienda">Buscar cédula en Hacienda</span>
                                    <span wire:loading wire:target="buscarEnHacienda">Buscando…</span>
                                </button>
                            </div>
                        </div>
                        @if ($avisoHacienda)
                            <p class="text-sm text-amber-700 dark:text-amber-300">{{ $avisoHacienda }}</p>
                        @endif
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
                    <input type="number" step="0.01" wire:model.live="declared_value" class="input">
                    @if ($this->insuranceFee > 0)
                        <p class="text-xs text-amber-700 dark:text-amber-300 mt-1">
                            Seguro del {{ rtrim(rtrim(number_format($empresa->porcentajeDeSeguro(), 2), '0'), '.') }}%:
                            <strong>₡{{ number_format($this->insuranceFee, 2) }}</strong> se suman al cobro.
                        </p>
                    @else
                        <p class="text-xs text-gray-500 mt-1">
                            Se cobra un {{ rtrim(rtrim(number_format($empresa->porcentajeDeSeguro(), 2), '0'), '.') }}%
                            de este valor como seguro.
                        </p>
                    @endif
                </div>
            </div>

            {{-- Entrega a domicilio: el destino deja de ser una sucursal donde el
                 cliente pasa a retirar, así que hace falta dirección y un cargo. --}}
            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <label class="inline-flex items-start gap-2 cursor-pointer">
                    <input type="checkbox" wire:model.live="home_delivery" class="checkbox mt-0.5">
                    <span>
                        <span class="font-medium">Entrega a domicilio</span>
                        <span class="block text-xs text-gray-500 dark:text-gray-400">
                            En vez de que el destinatario la retire en la sucursal de destino.
                        </span>
                    </span>
                </label>

                @if ($home_delivery)
                    <div class="grid gap-4 sm:grid-cols-3 mt-4">
                        <div class="sm:col-span-2">
                            <label class="label">Dirección exacta</label>
                            <input type="text" wire:model="delivery_address"
                                   placeholder="Provincia, cantón, distrito y señas exactas"
                                   class="input @error('delivery_address') input-error @enderror">
                            @error('delivery_address') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Cargo por domicilio (₡)</label>
                            <input type="number" step="0.01" wire:model.live="home_delivery_fee"
                                   class="input @error('home_delivery_fee') input-error @enderror">
                            @error('home_delivery_fee') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                    </div>
                @endif
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
                    @php $cantidad = max(1, (int) ($item['quantity'] ?? 1)); @endphp
                    {{-- Dos filas por paquete en pantalla ancha: con los siete campos
                         en una sola, cada columna quedaba de pocos píxeles y se
                         cortaban el tamaño («Mec») y la descripción. Las etiquetas
                         van en una línea para que los campos queden alineados. --}}
                    <div wire:key="item-{{ $index }}" class="grid grid-cols-2 lg:grid-cols-12 gap-x-3 gap-y-3 items-start border-b border-gray-100 dark:border-gray-700 pb-4">
                        <div class="col-span-2 lg:col-span-3">
                            <label class="label whitespace-nowrap">Tipo de bulto</label>
                            <select wire:model="items.{{ $index }}.package_type_id"
                                    class="input @error('items.'.$index.'.package_type_id') input-error @enderror">
                                @foreach ($tiposDeBulto as $tipo)
                                    <option value="{{ $tipo->id }}">{{ $tipo->name }}</option>
                                @endforeach
                            </select>
                            @error('items.'.$index.'.package_type_id') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        {{-- Varios bultos iguales en una sola línea: dos sobres no
                             necesitan dos líneas. --}}
                        <div class="lg:col-span-2">
                            <label class="label whitespace-nowrap">Cantidad</label>
                            <input type="number" min="1" step="1" inputmode="numeric" wire:model.live.debounce.400ms="items.{{ $index }}.quantity" class="input @error('items.'.$index.'.quantity') input-error @enderror">
                            @error('items.'.$index.'.quantity') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="lg:col-span-2">
                            <label class="label whitespace-nowrap">Tamaño</label>
                            <select wire:model="items.{{ $index }}.size" class="input @error('items.'.$index.'.size') input-error @enderror">
                                <option value="S">Pequeño</option>
                                <option value="M">Mediano</option>
                                <option value="L">Grande</option>
                                <option value="XL">Extra grande</option>
                            </select>
                            @error('items.'.$index.'.size') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2 lg:col-span-5">
                            <label class="label whitespace-nowrap">Descripción</label>
                            <input type="text" wire:model="items.{{ $index }}.description" class="input @error('items.'.$index.'.description') input-error @enderror">
                            @error('items.'.$index.'.description') <p class="error-text">{{ $message }}</p> @enderror
                        </div>

                        <div class="lg:col-span-2">
                            <label class="label whitespace-nowrap">Peso c/u (kg)</label>
                            <input type="number" step="0.01" wire:model.blur="items.{{ $index }}.weight" class="input @error('items.'.$index.'.weight') input-error @enderror">
                            @error('items.'.$index.'.weight') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div class="col-span-2 lg:col-span-4">
                            <label class="label whitespace-nowrap">L × A × H (cm)</label>
                            <div class="grid grid-cols-3 gap-1">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.length_cm" placeholder="Largo" class="input input-compacto">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.width_cm" placeholder="Ancho" class="input input-compacto">
                                <input type="number" step="0.1" inputmode="decimal" wire:model.blur="items.{{ $index }}.height_cm" placeholder="Alto" class="input input-compacto">
                            </div>
                        </div>
                        <div class="lg:col-span-2">
                            <label class="label whitespace-nowrap">Precio c/u (₡)</label>
                            <input type="number" step="0.01" wire:model.live="items.{{ $index }}.price" class="input @error('items.'.$index.'.price') input-error @enderror">
                            @error('items.'.$index.'.price') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        {{-- El total de la línea en su propia caja, del mismo alto
                             que los campos: debajo del precio desalineaba la fila. --}}
                        <div class="lg:col-span-2">
                            <span class="label whitespace-nowrap">Total línea</span>
                            <div class="input bg-gray-50 dark:bg-gray-900/40 tabular-nums" data-test="total-linea-{{ $index }}">
                                ₡{{ number_format((float) ($item['price'] ?? 0) * $cantidad, 2) }}
                            </div>
                        </div>
                        <div class="col-span-2 lg:col-span-2 flex items-end lg:pt-7">
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

            {{-- La exoneración es del cliente a quien se factura: se marca sola al
                 elegirlo y Hacienda la valida contra su cédula. --}}
            <div class="rounded-lg border p-4 {{ $tax_exempt
                    ? 'border-emerald-300 dark:border-emerald-700 bg-emerald-50 dark:bg-emerald-900/20'
                    : 'border-gray-200 dark:border-gray-700' }}" data-test="bloque-exoneracion">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" wire:model.live="tax_exempt" class="checkbox mt-0.5" data-test="exonerar-iva">
                    <span>
                        <span class="font-medium">Cliente exonerado de IVA</span>
                        <span class="block text-sm text-gray-500 dark:text-gray-400">
                            Solo con una exoneración autorizada (EXONET) registrada en el cliente a quien se factura.
                            Va en la Factura Electrónica con su número de autorización.
                        </span>
                    </span>
                </label>

                @if ($tax_exempt)
                    @if ($exoneracion)
                        <p class="mt-3 text-sm text-emerald-800 dark:text-emerald-200" data-test="exoneracion-aplicada">
                            Autorización <strong class="font-mono">{{ $exoneracion['numero'] }}</strong>
                            · {{ \App\Services\Hacienda\Catalogs::EXEMPTION_DOCUMENT_TYPES[$exoneracion['tipo']] ?? 'Otro documento' }}
                            · exonera {{ rtrim(rtrim(number_format((float) $exoneracion['tarifa'], 2), '0'), '.') }} puntos de IVA
                            @if (! empty($exoneracion['vence']))
                                · vence {{ \Carbon\Carbon::parse($exoneracion['vence'])->format('d/m/Y') }}
                            @endif
                        </p>
                    @elseif (! $wantsInvoice)
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">Marcá «Emitir Factura Electrónica» a nombre del cliente exonerado.</p>
                    @else
                        <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">
                            {{ $clienteFacturado ? $clienteFacturado->name . ' no tiene' : 'A quien se factura no es un cliente registrado con' }}
                            una exoneración registrada: se cobrará el IVA completo hasta que se registre en Clientes.
                        </p>
                    @endif
                @endif
                @error('tax_exempt') <p class="error-text mt-2">{{ $message }}</p> @enderror
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
                                @if (auth()->user()->puedeCobrar())
                                    El remitente paga ahora. Entra al arqueo de esta caja.
                                @else
                                    El remitente paga en caja. El paquete no sale hasta que se cobre.
                                @endif
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

                    {{-- La clave solo aparece cuando de verdad hace falta: si no
                         hay descuento, pedirla sería ruido. --}}
                    @if ((float) $discount_amount > 0 && $empresa->exigeClaveParaDescuento())
                        <div class="mt-2">
                            <label class="label">Clave de autorización</label>
                            <input type="password" wire:model="discountCode" autocomplete="off"
                                   class="input @error('discountCode') input-error @enderror">
                            @error('discountCode') <p class="error-text">{{ $message }}</p> @enderror
                            <p class="text-xs text-gray-500 mt-1">
                                Queda registrado que vos autorizaste este descuento.
                            </p>
                        </div>
                    @endif
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
                    <div class="flex justify-between"><span>Bultos</span><span data-test="resumen-bultos">₡{{ number_format($this->subtotal, 2) }}</span></div>
                    @if ($this->insuranceFee > 0)
                        <div class="flex justify-between">
                            <span>Seguro ({{ rtrim(rtrim(number_format($empresa->porcentajeDeSeguro(), 2), '0'), '.') }}% declarado)</span>
                            <span data-test="resumen-seguro">₡{{ number_format($this->insuranceFee, 2) }}</span>
                        </div>
                    @endif
                    @if ($this->homeDeliveryFeeAmount > 0)
                        <div class="flex justify-between">
                            <span>Entrega a domicilio</span>
                            <span data-test="resumen-domicilio">₡{{ number_format($this->homeDeliveryFeeAmount, 2) }}</span>
                        </div>
                    @endif
                    <div class="flex justify-between"><span>Descuento</span><span data-test="resumen-descuento">-₡{{ number_format((float) $discount_amount, 2) }}</span></div>
                    @if ($this->exemptTaxAmount > 0)
                        <div class="flex justify-between"><span>Impuestos</span><span>₡{{ number_format($this->grossTax, 2) }}</span></div>
                        <div class="flex justify-between text-emerald-700 dark:text-emerald-300">
                            <span>IVA exonerado</span><span data-test="resumen-exonerado">-₡{{ number_format($this->exemptTaxAmount, 2) }}</span>
                        </div>
                        <div class="flex justify-between"><span>Impuesto a cobrar</span><span data-test="resumen-impuestos">₡{{ number_format($this->taxTotal, 2) }}</span></div>
                    @else
                        <div class="flex justify-between"><span>Impuestos</span><span data-test="resumen-impuestos">₡{{ number_format($this->taxTotal, 2) }}</span></div>
                    @endif
                    <div class="flex justify-between text-lg font-bold border-t border-gray-200 dark:border-gray-700 pt-2">
                        <span>Total</span><span data-test="resumen-total">₡{{ number_format($this->total, 2) }}</span>
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
