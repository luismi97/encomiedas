<x-auth-layout title="Nueva contraseña">
    <h1 class="text-xl font-semibold mb-1">Crear una contraseña nueva</h1>
    <p class="text-sm text-gray-500 dark:text-gray-400 mb-4">Mínimo 8 caracteres.</p>

    @if ($errors->any())
        <div class="mb-4 p-3 rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 text-red-700 dark:text-red-200 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}" class="space-y-4" data-auth-form>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div>
            <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Correo electrónico</label>
            <input type="email" name="email" value="{{ old('email', $email) }}" required
                   class="input text-lg py-3 px-4">
        </div>
        <div>
            <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Contraseña nueva</label>
            <input type="password" name="password" required autofocus autocomplete="new-password"
                   class="input text-lg py-3 px-4">
        </div>
        <div>
            <label class="block text-base font-medium text-gray-700 dark:text-gray-200 mb-1">Repetir contraseña</label>
            <input type="password" name="password_confirmation" required autocomplete="new-password"
                   class="input text-lg py-3 px-4">
        </div>

        <button type="submit" data-auth-submit
            class="w-full py-3 rounded-lg bg-brand-600 hover:bg-brand-700 text-white text-lg font-semibold transition disabled:opacity-60 inline-flex items-center justify-center gap-2">
            <svg data-auth-spinner hidden class="animate-spin h-5 w-5" viewBox="0 0 24 24" fill="none"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
            <span data-auth-label>Guardar contraseña</span>
        </button>
    </form>
</x-auth-layout>
