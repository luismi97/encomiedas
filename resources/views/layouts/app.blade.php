<!DOCTYPE html>
<html lang="es" x-data="{ dark: localStorage.getItem('theme') === 'dark', sidebarOpen: false }"
      x-init="$watch('dark', v => { localStorage.setItem('theme', v ? 'dark' : 'light'); document.documentElement.classList.toggle('dark', v) }); document.documentElement.classList.toggle('dark', dark)"
      :class="{ 'dark': dark }">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @include('partials.tamano-letra')
    <title>{{ $title ?? config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @auth
        @include('offline.watchdog')
    @endauth
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
            @php($logo = \App\Models\CompanySetting::logoDeLaMarca())
            @if ($logo)
                {{-- object-contain y no cover: un logo recortado por el borde es
                     peor que uno pequeño, y los que suben las empresas vienen en
                     cualquier proporción. --}}
                <img src="{{ $logo }}" alt="" data-test="logo-empresa"
                     class="h-9 w-9 shrink-0 rounded-lg object-contain">
            @else
                <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-lg bg-brand-600 text-white">
                    <x-icon name="box" class="w-5 h-5" />
                </span>
            @endif
            {{-- truncate y title: un nombre de la base es más largo que el del
                 .env —«Transportes La Amistad S.A.»— y sin esto se sale del
                 sidebar. Al pasar el puntero se lee completo. --}}
            @php($marca = \App\Models\CompanySetting::marca())
            <span class="font-bold text-lg truncate" data-test="marca-empresa"
                  title="{{ $marca }}">{{ $marca }}</span>
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

            {{-- Los cierres los ven también el despachador y el dependiente, que no operan caja. --}}
            @if (auth()->user()->puedeDespachar())
                <a href="{{ route('dispatches.index') }}" class="nav-link {{ request()->routeIs('dispatches.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="truck" /> <span>Cierres de envío</span>
                </a>
            @endif

            @if (auth()->user()->puedeOperarCaja())
                @if (auth()->user()->puedeCobrar())
                    <a href="{{ route('caja.index') }}" class="nav-link {{ request()->routeIs('caja.*') ? 'nav-link-active' : '' }}">
                        <x-icon name="banknotes" /> <span>Caja</span>
                    </a>
                @endif
                <a href="{{ route('quotes.index') }}" class="nav-link {{ request()->routeIs('quotes.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="clipboard-list" /> <span>Cotizaciones</span>
                </a>
                <a href="{{ route('customers.index') }}" class="nav-link {{ request()->routeIs('customers.*') ? 'nav-link-active' : '' }}">
                    <x-icon name="users" /> <span>Clientes</span>
                </a>
                @if (auth()->user()->isAdmin())
                    <a href="{{ route('credito.index') }}" class="nav-link {{ request()->routeIs('credito.*') ? 'nav-link-active' : '' }}">
                        <x-icon name="receipt" /> <span>Crédito</span>
                    </a>
                    <a href="{{ route('reportes.index') }}" class="nav-link {{ request()->routeIs('reportes.*') ? 'nav-link-active' : '' }}">
                        <x-icon name="clipboard-list" /> <span>Reportes</span>
                    </a>
                @endif
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
        <header class="sticky top-0 z-20 h-16 flex items-center justify-between gap-3 px-3 sm:px-6 bg-white/90 dark:bg-gray-800/90 backdrop-blur border-b border-gray-200 dark:border-gray-700">
            {{-- min-w-0 + truncate, acá y en el bloque del usuario: con la
                 letra agrandada, el título y el nombre se recortan en vez de
                 empujar «Salir» fuera de la pantalla (WCAG 1.4.10). Los
                 botones no se achican nunca. --}}
            <div class="flex items-center gap-3 min-w-0">
                <button @click="sidebarOpen = !sidebarOpen" class="lg:hidden p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Abrir menú">
                    <x-icon name="menu" class="w-6 h-6" />
                </button>
                <h1 class="text-lg sm:text-xl font-semibold truncate" title="{{ $title ?? 'Inicio' }}">{{ $title ?? 'Inicio' }}</h1>
            </div>

            <div class="flex items-center gap-1 sm:gap-4 shrink-0 sm:shrink sm:min-w-0">
                {{-- Reabrir el recorrido de esta pantalla. Solo aparece donde
                     hay uno definido: un botón que a veces no hace nada enseña
                     a no confiar en él. --}}
                @if (\App\Support\GuiasDePantalla::para(request()->route()?->getName()))
                    <button type="button" x-on:click="$dispatch('abrir-guia')"
                            class="shrink-0 px-3 py-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700 text-sm font-medium
                                   inline-flex items-center gap-2"
                            data-test="boton-guia">
                        <x-icon name="clipboard-list" class="w-5 h-5" />
                        <span class="hidden sm:inline">Guía</span>
                    </button>
                @endif

                <x-tamano-letra />

                <button @click="dark = !dark" type="button" class="shrink-0 p-2 rounded-lg hover:bg-gray-100 dark:hover:bg-gray-700" aria-label="Cambiar tema">
                    <span x-show="!dark"><x-icon name="moon" class="w-5 h-5" /></span>
                    <span x-show="dark" x-cloak><x-icon name="sun" class="w-5 h-5" /></span>
                </button>

                <div class="hidden sm:block text-right leading-tight min-w-0">
                    <div class="font-medium truncate">{{ auth()->user()->name }}</div>
                    <div class="text-xs text-gray-500 dark:text-gray-400 truncate" data-test="identidad-sesion">
                        {{-- La empresa va primero: con varias en el mismo sistema,
                             saber en cuál se está trabajando importa más que el rol. --}}
                        @if ($empresaActiva = \App\Support\CompanyContext::actual())
                            <span class="font-medium text-gray-600 dark:text-gray-300">{{ $empresaActiva->name }}</span> ·
                        @endif
                        {{ auth()->user()->roleLabel() }}@if (auth()->user()->branch) · {{ auth()->user()->branch->name }}@endif
                    </div>
                </div>

                <form method="POST" action="{{ route('logout') }}" class="shrink-0">
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
            {{-- Lo rellena el watchdog del modo sin conexión: guías por subir,
                 etiquetas por imprimir y rechazadas. Solo lo sabe este navegador. --}}
            <div data-offline-aviso class="hidden mb-4 flex items-start justify-between gap-3 flex-wrap p-4 rounded-lg border border-amber-300 dark:border-amber-700 bg-amber-50 dark:bg-amber-900/40 text-amber-900 dark:text-amber-100 text-base">
                <span class="flex items-start gap-3">
                    <x-icon name="warning" class="w-5 h-5 mt-0.5 shrink-0" />
                    <span data-texto></span>
                </span>
                <a href="/guias-offline" class="font-semibold underline whitespace-nowrap">Ver guías sin conexión</a>
            </div>

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

    {{-- Llevar a la vista el formulario de alta/edición recién abierto.
         El formulario se dibuja arriba del listado; quien toca «Editar» en una
         fila de abajo no lo ve aparecer y cree que el botón no hizo nada. Cada
         formulario lo llama desde su x-init (ver las vistas *-index). --}}
    <script>
        window.mostrarFormulario = function (el) {
            // El encabezado es fijo: sin margen, el título quedaría debajo.
            el.style.scrollMarginTop = '5rem';
            el.scrollIntoView({ behavior: 'smooth', block: 'start' });
            el.querySelector('input:not([type=hidden]):not([disabled]), select:not([disabled]), textarea')
                ?.focus({ preventScroll: true });
        };

        // Llevar al primer error tras guardar. El botón está al pie y el error
        // suele quedar junto a un campo de arriba, fuera de la pantalla: quien
        // guardó no veía nada y creía que no había pasado nada.
        window.mostrarPrimerError = function (raiz) {
            const visible = (el) => el.getClientRects().length > 0;
            const aviso = Array.from(raiz.querySelectorAll('[data-aviso-error], .error-text, [data-resumen-errores]'))
                .find(visible);

            if (!aviso) {
                return;
            }

            aviso.style.scrollMarginTop = '5rem';
            aviso.scrollIntoView({ behavior: 'smooth', block: 'center' });

            // Junto al mensaje de un campo, el cursor queda listo en ese campo.
            if (aviso.matches('.error-text')) {
                aviso.parentElement
                    ?.querySelector('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([disabled]), select:not([disabled]), textarea')
                    ?.focus({ preventScroll: true });
            }
        };

        // Solo tras enviar un formulario (wire:submit): Livewire conserva los
        // errores entre peticiones, y sin esta condición cualquier clic
        // —«Agregar paquete», recotizar— volvería a saltar al error viejo.
        document.addEventListener('livewire:init', function () {
            const enviados = new Set();

            document.addEventListener('submit', function (e) {
                const esLivewire = Array.from(e.target.attributes || []).some((a) => a.name.startsWith('wire:submit'));
                const raiz = e.target.closest('[wire\\:id]');

                if (esLivewire && raiz) {
                    enviados.add(raiz.getAttribute('wire:id'));
                }
            }, true);

            Livewire.hook('commit', function ({ component, succeed }) {
                if (!enviados.has(component.id)) {
                    return;
                }

                enviados.delete(component.id);
                succeed(() => requestAnimationFrame(() => window.mostrarPrimerError(component.el)));
            });
        });
    </script>
</body>
</html>
