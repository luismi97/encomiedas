<!DOCTYPE html>
<html lang="es" x-data="{ dark: localStorage.getItem('theme') === 'dark' }" x-init="$watch('dark', v => { localStorage.setItem('theme', v ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', v) }); document.documentElement.classList.toggle('dark', dark)" :class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Iniciar sesión — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen flex items-center justify-center bg-gray-100 dark:bg-gray-900 px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-6">
            <h1 class="text-2xl font-bold text-gray-800 dark:text-white">📦 {{ config('app.name') }}</h1>
            <p class="text-gray-500 dark:text-gray-400 mt-1">Sistema de encomiendas</p>
        </div>

        <div class="bg-white dark:bg-gray-800 shadow-lg rounded-xl p-6 sm:p-8">
            @if ($errors->any())
                <div class="mb-4 p-3 rounded-lg bg-red-50 dark:bg-red-900/40 text-red-700 dark:text-red-200 text-sm">
                    {{ $errors->first() }}
                </div>
            @endif

            <form method="POST" action="{{ route('login') }}" class="space-y-4" x-data="{ submitting: false }" @submit="submitting = true">
                @csrf
                <div>
                    <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Correo o usuario</label>
                    <input type="text" name="login" value="{{ old('login') }}" required autofocus
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-lg py-3 px-4 focus:ring-2 focus:ring-brand-500">
                </div>
                <div>
                    <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Contraseña</label>
                    <input type="password" name="password" required
                        class="w-full rounded-lg border-gray-300 dark:border-gray-600 dark:bg-gray-700 dark:text-white text-lg py-3 px-4 focus:ring-2 focus:ring-brand-500">
                </div>
                <label class="flex items-center gap-2 text-sm text-gray-600 dark:text-gray-300">
                    <input type="checkbox" name="remember" class="rounded border-gray-300">
                    Recordarme
                </label>
                <button type="submit" :disabled="submitting"
                    class="w-full py-3 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-lg font-semibold transition disabled:opacity-60 inline-flex items-center justify-center gap-2">
                    <svg x-show="submitting" class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                    <span x-text="submitting ? 'Entrando...' : 'Entrar'"></span>
                </button>
            </form>
        </div>

        <button @click="dark = !dark" type="button"
            class="mt-6 mx-auto flex items-center gap-2 text-sm text-gray-500 dark:text-gray-300">
            <span x-show="!dark">🌙 Modo oscuro</span>
            <span x-show="dark">☀️ Modo claro</span>
        </button>
    </div>
</body>
</html>
