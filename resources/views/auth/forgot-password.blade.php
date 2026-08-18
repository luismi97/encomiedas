<x-auth-layout title="Recuperar contraseña">
    <h1 class="text-xl font-semibold mb-1">¿Olvidaste tu contraseña?</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">
        Escribí tu correo y te enviamos un enlace para crear una nueva.
    </p>

    @if (session('status'))
        <div class="mb-4 flex items-start gap-3 p-3 rounded-lg border border-green-200 dark:border-green-800 bg-green-50 dark:bg-green-900/30 text-green-800 dark:text-green-200 text-sm">
            <x-icon name="check-circle" class="w-5 h-5 mt-0.5 shrink-0" />
            <span>{{ session('status') }}</span>
        </div>
    @endif

    @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-200 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}" class="space-y-4" data-auth-form>
        @csrf
        <div>
            <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Correo electrónico</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="input text-lg py-3 px-4">
        </div>

        <button type="submit" data-auth-submit
            class="w-full py-3 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-lg font-semibold transition disabled:opacity-60 inline-flex items-center justify-center gap-2">
            <svg data-auth-spinner hidden class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span data-auth-label>Enviar enlace</span>
        </button>
    </form>

    <p class="mt-4 text-center text-sm">
        <a href="{{ route('login') }}" class="text-brand-600 dark:text-brand-300 font-medium">Volver al inicio de sesión</a>
    </p>
</x-auth-layout>
