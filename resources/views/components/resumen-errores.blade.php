{{--
    Junto al botón de guardar: si la validación falló, lo dice ahí mismo, donde
    está mirando quien tocó el botón. Sin esto el error quedaba pintado junto a
    un campo de más arriba, fuera de la pantalla, y parecía que el botón no
    había hecho nada. Lista todos los mensajes porque algunos (cobro, caja,
    exoneración) no tienen un campo propio al lado.
--}}
@props(['titulo' => 'No se guardó.'])

@if ($errors->any())
    @php $mensajes = array_values(array_unique($errors->all())); @endphp
    <div data-resumen-errores role="alert"
         class="flex items-start gap-3 p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200 text-sm">
        <x-icon name="warning" class="w-5 h-5 mt-0.5 shrink-0" />
        <div>
            <p class="font-semibold">
                {{ $titulo }} {{ count($mensajes) === 1 ? 'Falta corregir esto:' : 'Falta corregir ' . count($mensajes) . ' cosas:' }}
            </p>
            <ul class="list-disc list-inside mt-1 space-y-0.5">
                @foreach ($mensajes as $mensaje)
                    <li>{{ $mensaje }}</li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
