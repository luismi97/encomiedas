import './bootstrap';

// Livewire ya incluye y arranca Alpine.js internamente (via @livewireScripts).
// Arrancar una segunda instancia aqui rompe los listeners de Livewire
// (wire:click deja de disparar peticiones) y solo deja el warning
// "Detected multiple instances of Alpine running" como pista.
