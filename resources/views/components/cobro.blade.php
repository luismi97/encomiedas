@props(['guia'])

{{-- Si el flete de la guía está pagado o falta cobrarlo, y cuánto.
     En el cierre de envío le dice a quien carga y a quien recibe en destino
     qué paquetes no se entregan sin cobrar. --}}
@if ($guia)
    @if ($guia->tieneCobroPendiente())
        <span {{ $attributes->merge(['class' => 'badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 whitespace-nowrap']) }} data-test="cobro-guia">
            Por cobrar ₡{{ number_format((float) $guia->total, 2) }}
        </span>
    @elseif ($guia->esperandoCaja())
        <span {{ $attributes->merge(['class' => 'badge bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-200 whitespace-nowrap']) }} data-test="cobro-guia">Sin cobrar en caja</span>
    @elseif ($guia->esCredito())
        <span {{ $attributes->merge(['class' => 'badge bg-purple-100 text-purple-800 dark:bg-purple-900/40 dark:text-purple-200 whitespace-nowrap']) }} data-test="cobro-guia">Crédito</span>
    @else
        <span {{ $attributes->merge(['class' => 'badge bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200 whitespace-nowrap']) }} data-test="cobro-guia">Pagado</span>
    @endif
@endif
