@component('mail::message')
# {{ $electronicInvoice->typeLabel() }}

{{ $emisor['commercial_name'] ?: ($emisor['name'] ?? config('app.name')) }} le envía el comprobante electrónico aceptado por el Ministerio de Hacienda.

@component('mail::table')
| Documento | |
|:---|:---|
| Consecutivo | {{ $electronicInvoice->consecutivo }} |
| Clave | `{{ $electronicInvoice->clave }}` |
| Fecha de emisión | {{ $electronicInvoice->issued_at?->format('d/m/Y H:i') }} |
| Total | ₡{{ number_format((float) $electronicInvoice->total, 2) }} |
@endcomponent

Se adjuntan el XML firmado y el mensaje de respuesta de Hacienda, que son los documentos con validez tributaria, junto con la representación en PDF.

@if (!empty($emisor['email']))
Cualquier consulta sobre este comprobante: {{ $emisor['email'] }}
@endif

Gracias,<br>
{{ $emisor['commercial_name'] ?: ($emisor['name'] ?? config('app.name')) }}
@endcomponent
