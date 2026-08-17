<div class="space-y-6 max-w-4xl">
    <x-flash />

    <div class="card">
        <p class="text-gray-500 dark:text-gray-400 mb-4">
            Estos datos se usan para la facturación electrónica ante el Ministerio de Hacienda.
            Configure primero en <strong>sandbox</strong> y valide antes de pasar a producción.
        </p>

        <form wire:submit="save" class="space-y-6">
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2 text-base font-medium">
                    <input type="checkbox" wire:model="enabled" class="rounded w-5 h-5"> Facturación electrónica habilitada
                </label>
                <select wire:model="environment" class="input max-w-[160px]">
                    <option value="sandbox">Sandbox (pruebas)</option>
                    <option value="prod">Producción</option>
                </select>
            </div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Datos de la empresa</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="label">Razón social</label><input type="text" wire:model="name" class="input"></div>
                <div><label class="label">Nombre comercial</label><input type="text" wire:model="commercial_name" class="input"></div>
                <div>
                    <label class="label">Tipo de identificación</label>
                    <select wire:model="identification_type" class="input">
                        <option value="01">01 - Física</option>
                        <option value="02">02 - Jurídica</option>
                        <option value="03">03 - DIMEX</option>
                        <option value="04">04 - NITE</option>
                    </select>
                </div>
                <div><label class="label">Número de identificación</label><input type="text" wire:model="identification_number" class="input"></div>
                <div><label class="label">Código de actividad económica</label><input type="text" wire:model="activity_code" class="input" placeholder="6120.0"></div>
                <div class="sm:col-span-2">
                    <label class="label">Código CABYS por defecto (servicio de encomienda)</label>
                    <input type="text" wire:model="default_cabys_code" maxlength="13" inputmode="numeric" class="input">

                    <div class="mt-2 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
                        <p class="text-sm text-gray-500 dark:text-gray-400 mb-2">
                            Buscá el código en el catálogo de Hacienda por descripción, o pegá los 13 dígitos para validarlo.
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <input type="text" wire:model="cabysTerm" wire:keydown.enter.prevent="searchCabys"
                                   placeholder="ej. transporte de encomiendas" class="input flex-1 min-w-[220px]">
                            <x-action-button action="searchCabys" variant="secondary" loadingText="Buscando...">
                                Buscar
                            </x-action-button>
                        </div>

                        @if ($cabysMessage)
                            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">{{ $cabysMessage }}</p>
                        @endif

                        @if ($cabysResults)
                            <ul class="mt-3 space-y-2 max-h-64 overflow-y-auto">
                                @foreach ($cabysResults as $result)
                                    <li class="flex items-start justify-between gap-3 rounded-md border border-gray-200 dark:border-gray-700 p-2">
                                        <div class="min-w-0">
                                            <div class="font-mono text-sm">{{ $result['codigo'] }}</div>
                                            <div class="text-sm text-gray-600 dark:text-gray-300 break-words">{{ $result['descripcion'] }}</div>
                                            <div class="text-xs text-gray-500">IVA {{ rtrim(rtrim(number_format($result['impuesto'], 2), '0'), '.') }}%</div>
                                        </div>
                                        <x-action-button action="useCabys('{{ $result['codigo'] }}')" variant="link" class="shrink-0">
                                            Usar
                                        </x-action-button>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </div>
                </div>
            </div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Ubicación</h3>
            <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                <div><label class="label">Provincia</label><input type="text" wire:model="province" maxlength="1" class="input"></div>
                <div><label class="label">Cantón</label><input type="text" wire:model="canton" maxlength="2" class="input"></div>
                <div><label class="label">Distrito</label><input type="text" wire:model="district" maxlength="2" class="input"></div>
                <div><label class="label">Barrio</label><input type="text" wire:model="barrio" class="input"></div>
            </div>
            <div><label class="label">Otras señas</label><input type="text" wire:model="others_signs" class="input"></div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Contacto</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label class="label">Teléfono</label>
                    <div class="flex gap-2">
                        <input type="text" wire:model="phone_code" maxlength="3" inputmode="numeric"
                               class="input w-20 @error('phone_code') input-error @enderror" title="Código de país">
                        <input type="text" wire:model="phone" class="input flex-1 @error('phone') input-error @enderror">
                    </div>
                    @error('phone_code') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="email" class="input"></div>
            </div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Credenciales ATV y certificado</h3>

            @if ($unreadableFields)
                <div class="flex items-start gap-3 p-4 rounded-lg border border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5" />
                    <div>
                        <p class="font-medium">Hay credenciales guardadas que ya no se pueden leer</p>
                        <p class="text-sm mt-1">
                            Se cifraron con otra <code>APP_KEY</code>. Volvé a escribir estos campos y guardá:
                            <strong>{{ implode(', ', $unreadableFields) }}</strong>.
                            Mientras tanto, todo envío a Hacienda va a fallar.
                        </p>
                    </div>
                </div>
            @endif
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="label">Usuario ATV</label><input type="text" wire:model="atv_username" class="input"></div>
                <div><label class="label">Contraseña ATV</label><input type="password" wire:model="atv_password" class="input" placeholder="{{ $hasCertificate ? '••••••••' : '' }}"></div>
                <div>
                    <label class="label">Certificado (.p12) {{ $hasCertificate ? '— ya hay uno cargado' : '' }}</label>
                    <input type="file" wire:model="certificate" class="input" accept=".p12">
                    @error('certificate') <p class="error-text">{{ $message }}</p> @enderror
                </div>
                <div><label class="label">PIN del certificado</label><input type="password" wire:model="certificate_pin" class="input" placeholder="{{ $hasCertificate ? '••••••••' : '' }}"></div>
            </div>

            <div class="rounded-lg border border-gray-200 dark:border-gray-700 p-4">
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div>
                        <p class="font-medium">Probar conexión con Hacienda</p>
                        <p class="text-sm text-gray-500 dark:text-gray-400">
                            Pide un token con las credenciales ATV guardadas. Sin esto, el primer aviso de que están
                            mal es un comprobante fallido.
                        </p>
                    </div>
                    <x-action-button action="testConnection" variant="secondary" loadingText="Probando...">
                        <x-icon name="check-circle" class="w-4 h-4" /> Probar conexión
                    </x-action-button>
                </div>

                @if ($connectionTestMessage)
                    <div @class([
                        'mt-3 flex items-start gap-2 p-3 rounded-lg border text-sm',
                        'border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200' => $connectionTestStatus === 'success',
                        'border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200' => $connectionTestStatus === 'error',
                        'border-amber-200 dark:border-amber-800 bg-amber-50 dark:bg-amber-900/30 text-amber-800 dark:text-amber-200' => $connectionTestStatus === 'warning',
                    ])>
                        <x-icon :name="$connectionTestStatus === 'success' ? 'check-circle' : 'warning'" class="w-4 h-4 mt-0.5" />
                        <span>{{ $connectionTestMessage }}</span>
                    </div>
                @endif
            </div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Códigos de Hacienda por sucursal</h3>
            <p class="text-sm text-gray-500 dark:text-gray-400 -mt-2">
                El par sucursal + terminal viaja dentro del consecutivo de cada comprobante y el contador es por
                sucursal. Dos sucursales no pueden compartir el mismo par: ambas numerarían 1, 2, 3… por su lado y
                la segunda chocaría contra Hacienda con «el comprobante ya existe».
            </p>

            @if ($branches)
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="text-sm text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                                <th class="py-2">Sucursal</th>
                                <th class="py-2 w-40">Código sucursal</th>
                                <th class="py-2 w-40">Código terminal</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($branches as $i => $branch)
                                <tr class="border-b border-gray-100 dark:border-gray-700/50 align-top">
                                    <td class="py-3 pr-3">
                                        <div class="font-medium">{{ $branch['name'] }}</div>
                                        @if ($branch['locked'])
                                            <div class="text-xs text-amber-600 dark:text-amber-400 mt-0.5">
                                                Ya emitió comprobantes: códigos fijos
                                            </div>
                                        @endif
                                    </td>
                                    <td class="py-3 pr-3">
                                        <input type="text" maxlength="3" inputmode="numeric"
                                               wire:model="branches.{{ $i }}.sucursal_code"
                                               @disabled($branch['locked'])
                                               class="input @error('branches.'.$i.'.sucursal_code') input-error @enderror">
                                        @error('branches.'.$i.'.sucursal_code') <p class="error-text">{{ $message }}</p> @enderror
                                    </td>
                                    <td class="py-3">
                                        <input type="text" maxlength="5" inputmode="numeric"
                                               wire:model="branches.{{ $i }}.terminal_code"
                                               @disabled($branch['locked'])
                                               class="input @error('branches.'.$i.'.terminal_code') input-error @enderror">
                                        @error('branches.'.$i.'.terminal_code') <p class="error-text">{{ $message }}</p> @enderror
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <p class="text-sm text-gray-500">No hay sucursales registradas todavía.</p>
            @endif

            <div class="flex gap-3 pt-2">
                <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar configuración</x-action-button>
            </div>
        </form>
    </div>
</div>
