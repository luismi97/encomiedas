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
                <div><label class="label">Código CABYS por defecto (servicio de encomienda)</label><input type="text" wire:model="default_cabys_code" class="input"></div>
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
                <div><label class="label">Teléfono</label><input type="text" wire:model="phone" class="input"></div>
                <div><label class="label">Correo electrónico</label><input type="email" wire:model="email" class="input"></div>
            </div>

            <h3 class="font-semibold text-lg border-b border-gray-200 dark:border-gray-700 pb-2">Credenciales ATV y certificado</h3>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div><label class="label">Usuario ATV</label><input type="text" wire:model="atv_username" class="input"></div>
                <div><label class="label">Contraseña ATV</label><input type="password" wire:model="atv_password" class="input" placeholder="{{ $hasCertificate ? '••••••••' : '' }}"></div>
                <div>
                    <label class="label">Certificado (.p12) {{ $hasCertificate ? '— ya hay uno cargado' : '' }}</label>
                    <input type="file" wire:model="certificate" class="input" accept=".p12">
                    @error('certificate') <p class="text-red-600 text-sm mt-1">{{ $message }}</p> @enderror
                </div>
                <div><label class="label">PIN del certificado</label><input type="password" wire:model="certificate_pin" class="input" placeholder="{{ $hasCertificate ? '••••••••' : '' }}"></div>
            </div>

            <div class="flex gap-3 pt-2">
                <x-action-button type="submit" target="save" variant="primary" loadingText="Guardando...">Guardar configuración</x-action-button>
            </div>
        </form>
    </div>
</div>
