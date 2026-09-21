<div class="space-y-6">
    @if ($feedback)
        <div data-test="aviso"
             class="flex items-start gap-3 p-4 rounded-lg border text-base
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

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Empresas</div>
            <div class="text-2xl font-bold" data-test="total-empresas">{{ $totales['empresas'] }}</div>
        </div>
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Activas</div>
            <div class="text-2xl font-bold text-green-600 dark:text-green-400">{{ $totales['activas'] }}</div>
        </div>
        <div class="card">
            <div class="text-sm text-gray-500 dark:text-gray-400">Guías en el sistema</div>
            <div class="text-2xl font-bold">{{ number_format($totales['guias']) }}</div>
        </div>
    </div>

    <div class="flex items-center justify-between flex-wrap gap-3">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Buscar por nombre, cédula o correo"
               class="input max-w-sm" data-test="buscar-empresa">
        <x-action-button action="create" variant="primary" loadingText="Abriendo...">
            <x-icon name="plus" class="w-4 h-4" /> Nueva empresa
        </x-action-button>
    </div>

    @if ($showForm)
        <div class="card" data-test="formulario-empresa">
            <h2 class="text-lg font-semibold mb-1">{{ $editingId ? 'Editar empresa' : 'Nueva empresa' }}</h2>
            @unless ($editingId)
                <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
                    Se crea lista para trabajar: su sede, su caja, el IVA, los tipos de bulto y las
                    denominaciones del arqueo. Lo único que queda pendiente es el certificado de
                    Hacienda, que lo carga el cliente desde Configuración.
                </p>
            @endunless

            <form wire:submit="save" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Nombre de la empresa</label>
                    <input type="text" wire:model="name" class="input @error('name') input-error @enderror" data-test="empresa-nombre">
                    @error('name') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Razón social <span class="text-gray-400">(opcional)</span></label>
                    <input type="text" wire:model="legal_name" class="input @error('legal_name') input-error @enderror">
                    @error('legal_name') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Cédula jurídica <span class="text-gray-400">(opcional)</span></label>
                    <input type="text" wire:model="identification" class="input @error('identification') input-error @enderror">
                    @error('identification') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Correo de contacto <span class="text-gray-400">(opcional)</span></label>
                    <input type="email" wire:model="email" class="input @error('email') input-error @enderror">
                    @error('email') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Teléfono <span class="text-gray-400">(opcional)</span></label>
                    <input type="text" wire:model="phone" class="input @error('phone') input-error @enderror">
                    @error('phone') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="label">Vence el <span class="text-gray-400">(opcional)</span></label>
                    <input type="date" wire:model="expires_on" class="input @error('expires_on') input-error @enderror">
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Pasada esa fecha no pueden entrar. Dejalo vacío para sin vencimiento.</p>
                    @error('expires_on') <p class="error-text">{{ $message }}</p> @enderror
                </div>

                @unless ($editingId)
                    <div class="sm:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold">Primer acceso</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            El administrador con el que el cliente entra por primera vez. Después crea a su propia gente.
                        </p>
                    </div>
                    <div>
                        <label class="label">Nombre del administrador</label>
                        <input type="text" wire:model="admin_name" class="input @error('admin_name') input-error @enderror" data-test="admin-nombre">
                        @error('admin_name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Correo del administrador</label>
                        <input type="email" wire:model="admin_email" class="input @error('admin_email') input-error @enderror" data-test="admin-correo">
                        @error('admin_email') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Contraseña inicial</label>
                        <input type="text" wire:model="admin_password" class="input @error('admin_password') input-error @enderror" data-test="admin-clave">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Se muestra en claro para poder dictarla. Que la cambie al entrar.</p>
                        @error('admin_password') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-2 pt-2 border-t border-gray-200 dark:border-gray-700">
                        <h3 class="font-semibold">Primera sede</h3>
                        <p class="text-xs text-gray-500 dark:text-gray-400">
                            Una guía va de una sede a otra: sin al menos una, la pantalla de recepción abre vacía.
                        </p>
                    </div>
                    <div>
                        <label class="label">Nombre de la sede</label>
                        <input type="text" wire:model="branch_name" class="input @error('branch_name') input-error @enderror" data-test="sede-nombre">
                        @error('branch_name') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="label">Prefijo del código guía <span class="text-gray-400">(opcional)</span></label>
                        <input type="text" wire:model="branch_prefix" maxlength="4" placeholder="SJ"
                               class="input uppercase @error('branch_prefix') input-error @enderror" data-test="sede-prefijo">
                        <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Aparece en el código: <span class="font-mono">SJ-LIM-00005</span></p>
                        @error('branch_prefix') <p class="error-text">{{ $message }}</p> @enderror
                    </div>
                @endunless

                <div class="sm:col-span-2">
                    <label class="label">Notas internas <span class="text-gray-400">(opcional)</span></label>
                    <textarea wire:model="notes" rows="2" class="input @error('notes') input-error @enderror"></textarea>
                    @error('notes') <p class="error-text">{{ $message }}</p> @enderror
                </div>

                <div class="sm:col-span-2 flex gap-3 pt-2">
                    <x-action-button type="submit" target="save" variant="primary" loadingText="Creando...">
                        {{ $editingId ? 'Guardar' : 'Crear empresa' }}
                    </x-action-button>
                    <button type="button" wire:click="cancel" class="btn-secondary">Cancelar</button>
                </div>
            </form>
        </div>
    @endif

    <div class="card overflow-x-auto">
        <table class="min-w-full text-sm" data-test="tabla-empresas">
            <thead class="text-left text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <tr>
                    <th class="py-2 pr-4">Empresa</th>
                    <th class="py-2 pr-4">Estado</th>
                    <th class="py-2 pr-4 text-right">Sedes</th>
                    <th class="py-2 pr-4 text-right">Usuarios</th>
                    <th class="py-2 pr-4 text-right">Guías</th>
                    <th class="py-2 pr-4">Acciones</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100 dark:divide-gray-700">
                @forelse ($empresas as $empresa)
                    <tr data-test="empresa-fila" data-empresa="{{ $empresa->slug }}">
                        <td class="py-3 pr-4">
                            <div class="font-medium">{{ $empresa->name }}</div>
                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $empresa->identification ?: 'sin cédula' }}
                                @if ($empresa->email) · {{ $empresa->email }} @endif
                            </div>
                        </td>
                        <td class="py-3 pr-4">
                            @if (! $empresa->is_active)
                                <span class="px-2 py-0.5 rounded-full text-xs bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-200">Suspendida</span>
                            @elseif ($empresa->estaVencida())
                                <span class="px-2 py-0.5 rounded-full text-xs bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200">Vencida</span>
                            @else
                                <span class="px-2 py-0.5 rounded-full text-xs bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200">Activa</span>
                            @endif
                            @if ($empresa->expires_on)
                                <div class="text-xs text-gray-500 dark:text-gray-400 mt-1">hasta {{ $empresa->expires_on->format('d/m/Y') }}</div>
                            @endif
                        </td>
                        <td class="py-3 pr-4 text-right">{{ $empresa->branches_count }}</td>
                        <td class="py-3 pr-4 text-right">{{ $empresa->users_count }}</td>
                        <td class="py-3 pr-4 text-right">{{ number_format($empresa->invoices_count) }}</td>
                        <td class="py-3 pr-4">
                            <div class="flex flex-wrap items-center gap-2">
                                <form method="POST" action="{{ route('superadmin.companies.entrar', $empresa) }}">
                                    @csrf
                                    <button type="submit" class="btn-secondary !py-1 !px-2 text-xs" data-test="entrar-empresa">Entrar</button>
                                </form>
                                <button type="button" wire:click="edit({{ $empresa->id }})" class="btn-secondary !py-1 !px-2 text-xs">Editar</button>
                                <button type="button" wire:click="toggleActive({{ $empresa->id }})" class="btn-secondary !py-1 !px-2 text-xs" data-test="suspender-empresa">
                                    {{ $empresa->is_active ? 'Suspender' : 'Reactivar' }}
                                </button>
                                <button type="button" wire:click="$set('passwordForId', {{ $empresa->id }})" class="btn-secondary !py-1 !px-2 text-xs">
                                    Contraseña
                                </button>
                                <button type="button" wire:click="confirmDelete({{ $empresa->id }})" class="!py-1 !px-2 text-xs rounded-lg text-red-600 hover:bg-red-50 dark:hover:bg-red-900/30">
                                    Eliminar
                                </button>
                            </div>

                            {{-- Soporte: el cliente perdió el acceso y no tiene
                                 a mano el correo de recuperación. No se puede
                                 mostrar la anterior —está cifrada—, se reemplaza
                                 y se le dicta la nueva. --}}
                            @if ($passwordForId === $empresa->id)
                                <form method="POST" action="{{ route('superadmin.companies.clave', $empresa) }}"
                                      class="mt-2 p-3 rounded-lg border border-gray-200 dark:border-gray-700 space-y-2"
                                      data-test="clave-admin">
                                    @csrf
                                    <p class="text-xs text-gray-500 dark:text-gray-400">
                                        Contraseña nueva para
                                        <strong>{{ $empresa->admin()?->email ?? 'el administrador' }}</strong>.
                                        Dictásela y que la cambie al entrar.
                                    </p>
                                    <input type="text" name="password" required minlength="8"
                                           class="input !py-1 text-xs" placeholder="Al menos 8 caracteres">
                                    @error('password') <p class="error-text">{{ $message }}</p> @enderror
                                    <div class="flex gap-2">
                                        <button type="submit" class="btn-primary !py-1 !px-2 text-xs">Cambiar</button>
                                        <button type="button" wire:click="$set('passwordForId', null)" class="btn-secondary !py-1 !px-2 text-xs">Cancelar</button>
                                    </div>
                                </form>
                            @endif

                            @if ($deletingId === $empresa->id)
                                <div class="mt-2 p-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 space-y-2"
                                     data-test="confirmar-borrado">
                                    <p class="text-xs text-red-800 dark:text-red-200">
                                        Esto borra <strong>todo</strong> lo de «{{ $empresa->name }}»: guías, comprobantes,
                                        cajas y usuarios. No se puede deshacer. Si el cliente solo se va, suspendela.
                                    </p>
                                    <input type="text" wire:model="deleteConfirmation" class="input !py-1 text-xs"
                                           placeholder="Escribí «{{ $empresa->name }}» para confirmar">
                                    <div class="flex gap-2">
                                        <button type="button" wire:click="delete" class="!py-1 !px-2 text-xs rounded-lg bg-red-600 text-white hover:bg-red-700">
                                            Eliminar definitivamente
                                        </button>
                                        <button type="button" wire:click="cancelDelete" class="btn-secondary !py-1 !px-2 text-xs">Cancelar</button>
                                    </div>
                                </div>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="py-8 text-center text-gray-500 dark:text-gray-400">
                            Todavía no hay ninguna empresa. Creá la primera con «Nueva empresa».
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $empresas->links() }}
</div>
