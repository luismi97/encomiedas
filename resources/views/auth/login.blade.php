<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión — {{ config('app.name') }}</title>
    {{-- Antes del primer pintado para que no parpadee el tema claro. --}}
    <script>
        if (localStorage.getItem('theme') === 'dark') {
            document.documentElement.classList.add('dark');
        }
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white flex items-center justify-center gap-2"><span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white"><x-icon name="box" class="w-5 h-5" /></span>{{ config('app.name') }}</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Sistema de encomiendas</p>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow-lg rounded-xl p-6 sm:p-8">
            @if ($errors->any())
                <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-200 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4" data-login-form>
                @csrf
                <div>
                    <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Correo o usuario</label>
                    <input type="text" name="login" value="{{ old('login') }}" required autofocus
                        class="input text-lg py-3 px-4">
                </div>
                <div>
                    <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Contraseña</label>
                    <input type="password" name="password" required
                        class="input text-lg py-3 px-4">
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="remember" class="checkbox">
                    Recordarme
                </label>
                <button type="submit" data-login-submit
                    class="w-full py-3 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-lg font-semibold transition disabled:opacity-60 inline-flex items-center justify-center gap-2">
                    <svg data-login-spinner hidden class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span data-login-label>Entrar</span>
                </button>
            </form>
        </div>

        <button type="button" data-theme-toggle
            class="mt-6 mx-auto flex items-center gap-2 text-sm text-gray-500 dark:text-gray-300">
            <span class="inline-flex items-center gap-1.5 dark:hidden"><x-icon name="moon" class="w-4 h-4" /> Modo oscuro</span>
            <span class="hidden items-center gap-1.5 dark:inline-flex"><x-icon name="sun" class="w-4 h-4" /> Modo claro</span>
        </button>
    </div>

    {{--
        El login no pasa por el layout de la app, asi que no incluye
        @livewireScripts y por lo tanto tampoco Alpine (app.js no lo arranca a
        proposito, para no duplicar la instancia de Livewire). Toda la
        interactividad de esta pantalla va en JS plano.
    --}}
    <script>
        (function () {
            var form = document.querySelector('[data-login-form]');
            var button = document.querySelector('[data-login-submit]');
            var spinner = document.querySelector('[data-login-spinner]');
            var label = document.querySelector('[data-login-label]');

            function resetButton() {
                if (!button) return;
                button.disabled = false;
                spinner.hidden = true;
                label.textContent = 'Entrar';
            }

            if (form) {
                form.addEventListener('submit', function () {
                    button.disabled = true;
                    spinner.hidden = false;
                    label.textContent = 'Entrando...';
                });
            }

            // Al volver con el boton "atras" el navegador restaura el DOM tal
            // como quedo: sin esto el boton reaparece bloqueado y girando.
            window.addEventListener('pageshow', function (event) {
                if (event.persisted) resetButton();
            });

            var toggle = document.querySelector('[data-theme-toggle]');
            if (toggle) {
                toggle.addEventListener('click', function () {
                    var isDark = document.documentElement.classList.toggle('dark');
                    localStorage.setItem('theme', isDark ? 'dark' : 'light');
                });
            }
        })();
    </script>
</body>
</html>
