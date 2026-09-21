@props(['empresa' => null])

{{-- La empresa dueña de la guía, cuando se conoce: el portal es público y el
     destinatario reconoce a «Transportes López», no al nombre del sistema. --}}
@include('rastreo.layout', ['slot' => $slot, 'empresa' => $empresa])
