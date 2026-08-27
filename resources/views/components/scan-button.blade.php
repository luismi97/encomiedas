@props([
    // Método Livewire que se llama con cada código leído.
    'target',
    // Propiedad donde se deja el código antes de llamar al método.
    'model' => 'scanCode',
    // Propiedades de donde sale el aviso que se muestra dentro del overlay.
    'feedback' => 'feedback',
    'feedbackType' => 'feedbackType',
    'label' => 'Cámara',
])

{{-- Abre la cámara y, por cada código leído, lo deja en la propiedad y llama al
     método —igual que haría un lector físico al escribir y dar Enter—.

     La cámara NO se cierra tras la primera lectura: recibir un cierre son
     veinte guías seguidas, y abrirla veinte veces es inservible. El propio
     lector ignora la misma lectura repetida mientras la etiqueta siga frente
     al lente, así que dejarla abierta no duplica nada.

     Por eso mismo el resultado se muestra DENTRO del overlay: el overlay tapa
     la pantalla completa, así que el aviso que el componente pinta en la
     página queda debajo y no se ve hasta cerrar la cámara. Sin esto, escanear
     veinte guías es escanear a ciegas. --}}
<button type="button"
        x-data
        @click="
            if (! window.EncomiendasScanner) return;
            window.EncomiendasScanner.open({
                onDetected: async (code) => {
                    $wire.set('{{ $model }}', code);
                    await $wire.{{ $target }}();
                    window.EncomiendasScanner.notify(
                        $wire.get('{{ $feedback }}'),
                        $wire.get('{{ $feedbackType }}')
                    );
                },
            });
        "
        {{ $attributes->merge(['class' => 'btn-secondary inline-flex items-center gap-2']) }}
        title="Escanear con la cámara del dispositivo">
    <x-icon name="camera" class="w-4 h-4" />
    <span class="hidden sm:inline">{{ $label }}</span>
</button>
