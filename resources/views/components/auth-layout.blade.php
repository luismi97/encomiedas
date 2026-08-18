@props(['title' => 'Acceso'])
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }} — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    {{-- Antes del primer pintado para que no parpadee el tema claro. --}}
    <script>
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    </script>
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center justify-center gap-2">
                <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
                    <x-icon name="box" class="w-5 h-5" />
                </span>{{ config('app.name') }}
            </h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Sistema de encomiendas</p>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow-lg rounded-xl p-6 sm:p-8">
            {{ $slot }}
        </div>
    </div>

    {{--
        Estas pantallas no pasan por el layout de la app, así que no cargan
        Alpine. El estado del botón viene del servidor y JS solo lo cambia al
        enviar: si el script falla, el botón sigue legible.
    --}}
    <script>
        (function () {
            const form = document.querySelector('[data-auth-form]');
            const boton = document.querySelector('[data-auth-submit]');
            const spinner = document.querySelector('[data-auth-spinner]');
            const label = document.querySelector('[data-auth-label]');
            if (!form || !boton) return;

            const original = label.textContent;

            form.addEventListener('submit', function () {
                boton.disabled = true;
                spinner.hidden = false;
                label.textContent = 'Enviando...';
            });

            // Al volver con "atrás" el navegador restaura el DOM tal como quedó.
            window.addEventListener('pageshow', function (e) {
                if (!e.persisted) return;
                boton.disabled = false;
                spinner.hidden = true;
                label.textContent = original;
            });
        })();
    </script>
</body>
</html>
