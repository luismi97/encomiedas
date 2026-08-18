<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Seguimiento de encomienda — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css'])
    {{-- Antes del primer pintado para que no parpadee el tema claro. --}}
    <script>
        if (localStorage.getItem('theme') === 'dark') document.documentElement.classList.add('dark');
    </script>
</head>
{{-- Mobile-first: la mayoría llega escaneando el QR con el celular. --}}
<body class="min-h-screen bg-gray-100 dark:bg-gray-900 text-gray-800 dark:text-gray-100">
    <div class="max-w-2xl mx-auto px-4 py-8 sm:py-12">
        <header class="flex items-center gap-3 mb-8">
            <span class="flex h-10 w-10 items-center justify-center rounded-lg bg-brand-600 text-white shrink-0">
                <x-icon name="box" class="w-6 h-6" />
            </span>
            <div>
                <div class="font-bold text-lg leading-tight">{{ config('app.name') }}</div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Seguimiento de encomiendas</div>
            </div>
        </header>

        {{ $slot }}

        <footer class="mt-10 text-center text-xs text-gray-400 dark:text-gray-500">
            Para consultas sobre una encomienda, comuníquese con la sucursal indicando su código de guía.
        </footer>
    </div>
</body>
</html>
