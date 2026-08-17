@props(['electronicInvoice'])
@php
    $errores = $electronicInvoice->rejectionErrors();
@endphp

<div class="rounded-lg border border-red-200 dark:border-red-800 bg-red-50 dark:bg-red-900/30 p-4">
    <div class="flex items-start gap-3">
        <x-icon name="warning" class="w-5 h-5 mt-0.5 text-red-600 dark:text-red-400" />
        <div class="flex-1 min-w-0">
            <h4 class="font-semibold text-red-800 dark:text-red-200">Rechazado por Hacienda</h4>
            @if ($electronicInvoice->rejected_at)
                <p class="text-xs text-red-700/80 dark:text-red-300/80 mt-0.5">
                    {{ $electronicInvoice->rejected_at->format('d/m/Y H:i') }}
                </p>
            @endif

            @if ($errores)
                <ul class="mt-3 space-y-2">
                    @foreach ($errores as $error)
                        <li class="rounded-md bg-white/70 dark:bg-gray-900/40 border border-red-200 dark:border-red-800/60 p-3">
                            <div class="flex items-start justify-between gap-3">
                                <span class="font-medium text-red-900 dark:text-red-100">
                                    {{ $error['description'] ?: 'Error reportado por Hacienda' }}
                                </span>
                                @if ($error['code'])
                                    <span class="shrink-0 font-mono text-xs px-1.5 py-0.5 rounded bg-red-100 dark:bg-red-900/60 text-red-800 dark:text-red-200">
                                        {{ $error['code'] }}
                                    </span>
                                @endif
                            </div>
                            @if ($error['message'] && $error['message'] !== $error['description'])
                                <p class="mt-1 text-sm text-red-800/90 dark:text-red-200/90 break-words">{{ $error['message'] }}</p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @elseif ($electronicInvoice->error_message)
                <p class="mt-2 text-sm text-red-800 dark:text-red-200 break-words">{{ $electronicInvoice->error_message }}</p>
            @endif

            <div class="mt-3 flex flex-wrap items-center gap-4 text-sm">
                @if ($electronicInvoice->response_xml_path)
                    <a href="{{ route('electronic-invoices.response-xml', $electronicInvoice) }}" target="_blank"
                       class="inline-flex items-center gap-1.5 text-red-800 dark:text-red-200 font-medium underline">
                        <x-icon name="document" class="w-4 h-4" /> Ver XML de respuesta
                    </a>
                @endif
                <span class="text-xs text-red-700/80 dark:text-red-300/80">
                    Al reintentar se emite con una clave y consecutivo nuevos: la clave rechazada queda consumida.
                </span>
            </div>
        </div>
    </div>
</div>
