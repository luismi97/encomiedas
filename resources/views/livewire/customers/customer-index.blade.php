<div class="space-y-6">
    @if ($feedback)
        <div class="flex items-start gap-3 p-4 rounded-lg border text-base
            {{ $feedbackType === 'error'
                ? 'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200'
                : 'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200' }}">
            <x-icon name="{{ $feedbackType === 'error' ? 'warning' : 'check-circle' }}" class="w-5 h-5 mt-0.5" />
            <span class="flex-1">{{ $feedback }}</span>
            <button type="button" wire:click="dismissFeedback" class="opacity-60 hover:opacity-100" aria-label="Cerrar aviso">
                <x-icon name="x" class="w-4 h-4" />
            </button>
        </div>
    @endif

    <div class="flex items-center justify-between flex-wrap gap-3">
        <p class="text-gray-500 dark:text-gray-400">Remitentes y destinatarios registrados, de contado y de crédito.</p>
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nuevo cliente
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card">
            <h2 class="text-lg font-semibold mb-4">{{ $editingId ? 'Editar cliente' : 'Nuevo cliente' }}</h2>

            <form wire:submit="save" class="space-y-4">
                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                    <div>
                        <label class="label">Nombre o razón social</label>
                        <input type="text" wire:model="name" class="input @error('name') input-error @enderror">
                        @error('name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Nombre comercial</label>
                        <input type="text" wire:model="commercial_name" class="input">
                    </div>
                </div>

                <h3 class="font-semibold border-b border-gray-200 dark:border-gray-700 pb-2">Datos fiscales</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 -mt-2">
                    Sin identificación el comprobante sale como Tiquete Electrónico. Con ella, como Factura Electrónica.
                </p>

                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Tipo de identificación</label>
                        <select wire:model="identification_type" class="input @error('identification_type') input-error @enderror">
                            @foreach (\App\Models\Customer::IDENTIFICATION_TYPES as $codigo => $etiqueta)
                                <option value="{{ $codigo }}">{{ $codigo }} - {{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="label">Identificación</label>
                        <input type="text" wire:model="identification" inputmode="numeric" maxlength="12"
                               placeholder="Sin guiones" class="input @error('identification') input-error @enderror">
                        @error('identification') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Código de actividad</label>
                        <input type="text" wire:model="activity_code" maxlength="6"
                               class="input @error('activity_code') input-error @enderror">
                        @error('activity_code') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                </div>

                <h3 class="font-semibold border-b border-gray-200 dark:border-gray-700 pb-2">Contacto</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Correo electrónico</label>
                        <input type="email" wire:model="email" class="input @error('email') input-error @enderror">
                        @error('email') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Teléfono</label>
                        <input type="text" wire:model="phone" class="input">
                    </div>
                    <div>
                        <label class="label">Sede habitual</label>
                        <select wire:model="branch_id" class="input">
                            <option value="">— Ninguna —</option>
                            @foreach ($branches as $branch)
                                <option value="{{ $branch->id }}">{{ $branch->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
                <div>
                    <label class="label">Dirección</label>
                    <input type="text" wire:model="address" class="input">
                </div>

                <h3 class="font-semibold border-b border-gray-200 dark:border-gray-700 pb-2">Condición de pago</h3>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                    <div>
                        <label class="label">Condición</label>
                        <select wire:model.live="payment_condition" class="input">
                            @foreach (\App\Models\Customer::PAYMENT_CONDITIONS as $valor => $etiqueta)
                                <option value="{{ $valor }}">{{ $etiqueta }}</option>
                            @endforeach
                        </select>
                    </div>
                    @if ($payment_condition === \App\Models\Customer::PAYMENT_CREDIT)
                        <div>
                            <label class="label">Límite de crédito (₡)</label>
                            <input type="number" step="0.01" wire:model="credit_limit"
                                   class="input @error('credit_limit') input-error @enderror">
                            @error('credit_limit') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                        <div>
                            <label class="label">Día de corte</label>
                            <input type="number" min="1" max="31" wire:model="credit_cutoff_day"
                                   placeholder="30" class="input @error('credit_cutoff_day') input-error @enderror">
                            @error('credit_cutoff_day') <p class="error-text">{{ $message }}</p> @enderror
                        </div>
                    @endif
                </div>

                <div>
                    <label class="label">Notas</label>
                    <textarea wire:model="notes" rows="2" class="input"></textarea>
                </div>

                <label class="inline-flex items-center gap-2">
                    <input type="checkbox" wire:model="is_active" class="checkbox">
                    <span class="text-sm font-medium text-gray-700 dark:text-gray-300">Cliente activo</span>
                </label>

                <div class="flex gap-3 pt-2">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar</x-action-button>
                    <button type="button" wire:click="$set('showForm', false)" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card space-y-4">
        <div class="flex flex-wrap gap-3">
            <input type="text" wire:model.live.debounce.300ms="search"
                   placeholder="Buscar por nombre, identificación, correo o teléfono"
                   class="input flex-1 min-w-[240px]">
            <select wire:model.live="filterCondition" class="input sm:max-w-[180px]">
                <option value="">Todas las condiciones</option>
                @foreach (\App\Models\Customer::PAYMENT_CONDITIONS as $valor => $etiqueta)
                    <option value="{{ $valor }}">{{ $etiqueta }}</option>
                @endforeach
            </select>
        </div>

        <div class="data-table-wrap">
            <table class="w-full text-left">
                <thead>
                    <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                        <th class="py-2">Cliente</th>
                        <th class="py-2">Identificación</th>
                        <th class="py-2">Contacto</th>
                        <th class="py-2">Condición</th>
                        <th class="py-2">Estado</th>
                        <th class="py-2 text-right">Acciones</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($customers as $customer)
                        <tr class="border-b border-gray-100 dark:border-gray-700/50 align-top">
                            <td class="py-3">
                                <div class="font-medium">{{ $customer->name }}</div>
                                @if ($customer->commercial_name)
                                    <div class="text-sm text-gray-500">{{ $customer->commercial_name }}</div>
                                @endif
                                @if ($customer->branch)
                                    <div class="text-xs text-gray-400">{{ $customer->branch->name }}</div>
                                @endif
                            </td>
                            <td class="py-3 text-sm">
                                @if ($customer->identification)
                                    <span class="font-mono">{{ $customer->identification }}</span>
                                    <div class="text-xs text-gray-500">{{ $customer->identificationTypeLabel() }}</div>
                                @else
                                    <span class="text-gray-400">— solo tiquete —</span>
                                @endif
                            </td>
                            <td class="py-3 text-sm">
                                {{ $customer->email ?: '—' }}
                                @if ($customer->phone)<div class="text-xs text-gray-500">{{ $customer->phone }}</div>@endif
                            </td>
                            <td class="py-3">
                                <span class="badge {{ $customer->isCredit()
                                    ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                                    : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                                    {{ $customer->paymentConditionLabel() }}
                                </span>
                                @if ($customer->isCredit())
                                    <div class="text-xs text-gray-500 mt-1">
                                        Límite ₡{{ number_format($customer->credit_limit, 2) }}
                                        @if ($customer->credit_cutoff_day) · corte {{ $customer->credit_cutoff_day }} @endif
                                    </div>
                                @endif
                            </td>
                            <td class="py-3">
                                <x-action-button action="toggleActive({{ $customer->id }})" variant="link" loadingText="..."
                                    class="badge {{ $customer->is_active
                                        ? 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200'
                                        : 'bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-300' }}">
                                    {{ $customer->is_active ? 'Activo' : 'Inactivo' }}
                                </x-action-button>
                            </td>
                            <td class="py-3 text-right whitespace-nowrap">
                                <x-action-button action="edit({{ $customer->id }})" variant="link">Editar</x-action-button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="py-6 text-center text-gray-500">No hay clientes que coincidan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="md:hidden space-y-3">
            @forelse ($customers as $customer)
                <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $customer->name }}</div>
                            @if ($customer->identification)
                                <div class="text-sm font-mono text-gray-500">{{ $customer->identification }}</div>
                            @endif
                        </div>
                        <span class="badge shrink-0 {{ $customer->isCredit()
                            ? 'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200'
                            : 'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-200' }}">
                            {{ $customer->paymentConditionLabel() }}
                        </span>
                    </div>
                    <div class="mt-2 space-y-1 text-sm">
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Correo</span><span class="text-right">{{ $customer->email ?: '—' }}</span></div>
                        <div class="flex justify-between gap-3"><span class="text-gray-500">Teléfono</span><span>{{ $customer->phone ?: '—' }}</span></div>
                    </div>
                    <div class="mt-3 pt-3 border-t border-gray-100 dark:border-gray-700 flex gap-4">
                        <x-action-button action="edit({{ $customer->id }})" variant="link">Editar</x-action-button>
                        <x-action-button action="toggleActive({{ $customer->id }})" variant="link" loadingText="...">
                            {{ $customer->is_active ? 'Desactivar' : 'Activar' }}
                        </x-action-button>
                    </div>
                </div>
            @empty
                <div class="text-center text-gray-500 py-6">No hay clientes que coincidan.</div>
            @endforelse
        </div>

        <div>{{ $customers->links() }}</div>
    </div>
</div>
