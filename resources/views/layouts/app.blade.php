<!DOCTYPE html>
<html lang="es" x-data="{ dark: localStorage.getItem('theme') === 'dark', sidebarOpen: false }"
      x-init="$watch('dark', v => { localStorage.setItem('theme', v ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', v) }); document.documentElement.classList.toggle('dark', dark)"
      :class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-gray-50 dark:bg-gray-900 text-gray-800 dark:text-gray-100 text-base">

    <!-- Barra de carga global: cualquier accion de Livewire la enciende -->
    <div wire:loading.delay class="loading-bar"></div>

    <!-- Overlay móvil -->
    <div x-show="sidebarOpen" x-cloak @click="sidebarOpen = false"
         class="fixed inset-0 bg-black/40 z-30 lg:hidden"></div>

    <!-- Sidebar -->
    {{-- Columna flex: la marca queda fija arriba y el menú se lleva el alto
         restante. Sin esto, al pasar de una docena de opciones las últimas
         caen fuera de la pantalla y no hay forma de alcanzarlas. --}}
    <aside
        class="fixed inset-y-0 left-0 z-40 w-72 flex flex-col bg-white dark:bg-gray-800 border-r border-gray-200 dark:border-gray-700 transform transition-transform lg:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'">
        <div class="h-16 shrink-0 flex items-center gap-2 px-5 border-b border-gray-200 dark:border-gray-700">
            <span class="flex h-9 w-9 items-center justify-center rounded-lg bg-brand-600 text-white">
                <x-icon name="box" class="w-5 h-5" />
            </span>
            <span class="font-bold text-lg">{{ config('app.name') }}</span>
        </div>

        {{-- overscroll-contain evita que al llegar al final del menú el gesto
             siga desplazando la página de atrás. --}}
        <nav class="flex-1 overflow-y-auto overscroll-contain p-3 space-y-1">
            {{-- El superadministrador no opera ninguna empresa: no tiene sede ni
                 caja, así que las pantallas de operación no sabrían qué
                 mostrarle. Su menú es el de las empresas, y para entrar a una
                 usa «Entrar» desde el listado. --}}
            @if (auth()->user()->isSuperadmin())
                <a href="{{ route('superadmin.companies.index') }}" class="nav-link {{ request()->routeIs('superadmin.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="building" /> <span>Empresas</span>
                </a>
            @else
            <a href="{{ route('dashboard') }}" class="nav-link {{ request()->routeIs('dashboard') ? 'nav-link-active' : '' }}">
                <x-icon name="home" /> <span>Inicio</span>
            </a>
            <a href="{{ route('invoices.index') }}" class="nav-link {{ request()->routeIs('invoices.*') ? 'nav-link-active' : '' }}">
                <x-icon name="clipboard-list" /> <span>Facturas / Encomiendas</span>
            </a>

            @if (auth()->user()->isRepartidor() || auth()->user()->isAdmin())
                <a href="{{ route('chofer.index') }}" class="nav-link {{ request()->routeIs('chofer.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="truck" /> <span>Mi ruta</span>
                </a>
            @endif

            {{-- Los cierres los ve también el despachador, que no opera caja. --}}
            @if (auth()->user()->puedeDespachar())
                <a href="{{ route('dispatches.index') }}" class="nav-link {{ request()->routeIs('dispatches.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="truck" /> <span>Cierres de envío</span>
                </a>
            @endif

            @if (auth()->user()->puedeOperarCaja())
                <a href="{{ route('caja.index') }}" class="nav-link {{ request()->routeIs('caja.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="banknotes" /> <span>Caja</span>
                </a>
                <a href="{{ route('quotes.index') }}" class="nav-link {{ request()->routeIs('quotes.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="clipboard-list" /> <span>Cotizaciones</span>
                </a>
                <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="users" /> <span>Clientes</span>
                </a>
                <a href="{{ route('credito.index') }}" class="nav-link {{ request()->routeIs('credito.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="receipt" /> <span>Crédito</span>
                </a>
                <a href="{{ route('reportes.index') }}" class="nav-link {{ request()->routeIs('reportes.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="clipboard-list" /> <span>Reportes</span>
                </a>
            @endif

            @if (auth()->user()->puedeConfigurar())
                <a href="{{ route('hacienda.pending') }}" class="nav-link {{ request()->routeIs('hacienda.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="receipt" /> <span>Pendientes de envío a Hacienda</span>
                </a>
                <a href="{{ route('branches.index') }}" class="nav-link {{ request()->routeIs('branches.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="building" /> <span>Sucursales</span>
                </a>
                <a href="{{ route('cash-registers.index') }}" class="nav-link {{ request()->routeIs('cash-registers.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="banknotes" /> <span>Cajas</span>
                </a>
                <a href="{{ route('rates.index') }}" class="nav-link {{ request()->routeIs('rates.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="banknotes" /> <span>Tarifario</span>
                </a>
                <a href="{{ route('shipping-routes.index') }}" class="nav-link {{ request()->routeIs('shipping-routes.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="truck" /> <span>Rutas</span>
                </a>
                <a href="{{ route('package-types.index') }}" class="nav-link {{ request()->routeIs('package-types.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="box" /> <span>Tipos de bulto</span>
                </a>
                <a href="{{ route('taxes.index') }}" class="nav-link {{ request()->routeIs('taxes.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="receipt" /> <span>Impuestos</span>
                </a>
                <a href="{{ route('users.index') }}" class="nav-link {{ request()->routeIs('users.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="users" /> <span>Usuarios</span>
                </a>
                <a href="{{ route('activity-logs.index') }}" class="nav-link {{ request()->routeIs('activity-logs.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="clock" /> <span>Actividad de usuarios</span>
                </a>
                <a href="{{ route('settings.company') }}" class="nav-link {{ request()->routeIs('settings.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="cog" /> <span>Configuración de la empresa</span>
                </a>
            @endif
            @endif
        </nav>
    </aside>

    <div class="lg:pl-72">
        <!-- Topbar -->
        <header class="sticky top-0 z-20 h-16 flex items-center justify-between gap-3 px-4 sm:px-6 bg-white/90 dark:bg-gray-800/90 backdrop-blur border-b border-gray-200 dark:border-gray-700">
            <div class="flex items-center gap-3">
                <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Abrir menú">
                    <x-icon name="menu" class="w-6 h-6" />
                </button>
                <h1 class="text-lg sm:text-xl font-semibold">{{ $title ?? 'Inicio' }}</h1>
            </div>

            <div class="flex items-center gap-2 sm:gap-4">
                {{-- Reabrir el recorrido de esta pantalla. Solo aparece donde
                     hay uno definido: un botón que a veces no hace nada enseña
                     a no confiar en él. --}}
                @if (\App\Support\GuiasDePantalla::para(request()->route()?->getName()))
                    <button type="button" x-on:click="$dispatch('abrir-guia')"
                            class="px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-sm font-medium
                                   inline-flex items-center gap-2"
                            data-test="boton-guia">
                        <x-icon name="clipboard-list" class="w-5 h-5" />
                        <span class="hidden sm:inline">Guía</span>
                    </button>
                @endif

                <button @click="dark = !dark" type="button" class="p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cambiar tema">
                    <span x-show="!dark"><x-icon name="moon" class="w-5 h-5" /></span>
                    <span x-show="dark" x-cloak><x-icon name="sun" class="w-5 h-5" /></span>
                </button>

                <div class="hidden sm:block text-right leading-tight">
                    <div class="font-medium">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400" data-test="identidad-sesion">
                        {{-- La empresa va primero: con varias en el mismo sistema,
                             saber en cuál se está trabajando importa más que el rol. --}}
                        @if ($empresaActiva = \App\Support\CompanyContext::actual())
                            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $empresaActiva->name }}</span> ·
                        @endif
                        {{ auth()->user()->roleLabel() }}@if (auth()->user()->branch) · {{ auth()->user()->branch->name }}@endif
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="px-3 py-2 rounded-lg bg-gray-100 dark:bg-gray-700 hover:bg-gray-200 dark:hover:bg-gray-600 text-sm font-medium">
                        Salir
                    </button>
                </form>
            </div>
        </header>

        {{-- Suplantación: una franja imposible de no ver. El superadministrador
             está operando con la identidad de un cliente y todo lo que haga acá
             queda a nombre de esa persona; confundirse de pestaña y cobrar una
             guía en la empresa equivocada es exactamente lo que esto evita. --}}
        @if (\App\Support\Impersonation::activa())
            <div class="flex items-center justify-between gap-3 flex-wrap px-4 sm:px-6 py-2 bg-amber-100 dark:bg-amber-900/50 border-b border-amber-300 dark:border-amber-700 text-amber-900 dark:text-amber-100 text-sm"
                 data-test="aviso-suplantacion">
                <span class="flex items-center gap-2">
                    <x-icon name="warning" class="w-4 h-4" />
                    Estás dentro de
                    <strong>{{ \App\Support\CompanyContext::actual()?->name ?? 'una empresa' }}</strong>
                    como {{ auth()->user()->name }}.
                </span>
                <form method="POST" action="{{ route('superadmin.volver') }}">
                    @csrf
                    <button type="submit" class="px-3 py-1 rounded-lg bg-amber-800 text-white hover:bg-amber-900 font-medium">
                        Volver al panel
                    </button>
                </form>
            </div>
        @endif

        <main class="p-4 sm:p-6">
            @if (session('success'))
                <div class="mb-4 flex items-start gap-3 p-4 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/40 text-green-800 dark:text-green-200 text-base">
                    <x-icon name="check-circle" class="w-5 h-5 mt-0.5" />
                    <span>{{ session('success') }}</span>
                </div>
            @endif
            @if (session('error'))
                <div class="mb-4 flex items-start gap-3 p-4 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/40 text-red-800 dark:text-red-200 text-base">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5" />
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            {{ $slot }}
        </main>
    </div>

    {{-- La guía de la pantalla. Va acá y no dentro del <main> porque su tarjeta
         es fija y no forma parte del contenido que cada pantalla reemplaza. --}}
    @auth
        @livewire('ayuda.guia-de-pantalla', ['clave' => request()->route()?->getName()])
    @endauth

    {{-- Fuera del contenido Livewire: el morph destruiría el <video>. --}}
    <x-barcode-scanner />

    @livewireScripts
</body>
</html>
