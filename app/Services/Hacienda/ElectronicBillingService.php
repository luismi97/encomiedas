<?php

namespace App\Services\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Notifications\SendElectronicInvoice;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Orquesta el ciclo de vida del comprobante electrónico de una factura de
 * encomienda: reservar clave -> (en cola) -> construir XML -> firmar ->
 * transmitir -> seguir el estado.
 *
 * A diferencia de un POS, aquí el envío NUNCA es automático: cuando la
 * factura se marca "entregada" solo se reserva la clave y se agrega a la
 * lista de "pendientes de envío a Hacienda" (ver queueForInvoice). Un
 * administrador decide después, uno por uno o en bloque, cuáles transmitir.
 */
class ElectronicBillingService
{
    public function __construct(
        private ClaveGenerator $claveGenerator,
        private XadesSigner $signer,
        private HaciendaClient $client,
    ) {
    }

    private function disk()
    {
        return Storage::disk(config('hacienda.disk'));
    }

    /**
     * Se llama cuando una factura pasa a "entregada". Reserva la clave y dej
     * a el comprobante en estado 'pending' (lista de pendientes de envío).
     * Idempotente: si ya existe un comprobante para la factura, no hace nada.
     */
    public function queueForInvoice(Invoice $invoice): ?ElectronicInvoice
    {
        $settings = CompanySetting::instance();
        if (!$settings->isReady()) {
            Log::info("Hacienda: factura {$invoice->id} no se encoló (facturación electrónica no configurada).");
            return null;
        }

        return DB::transaction(function () use ($invoice, $settings) {
            Invoice::whereKey($invoice->id)->lockForUpdate()->first();

            $existing = ElectronicInvoice::where('invoice_id', $invoice->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            return $this->createPendingInvoice($invoice, $settings);
        });
    }

    private function createPendingInvoice(Invoice $invoice, CompanySetting $settings): ElectronicInvoice
    {
        $letter   = $invoice->receptorIdentificado() ? 'FE' : 'TE';
        $docCode  = Catalogs::documentCode($letter);
        $issuedAt = Carbon::now(config('app.timezone') ?: 'America/Costa_Rica');

        $branch = $invoice->pickupBranch;

        $clave = $this->claveGenerator->generate(
            $branch,
            $docCode,
            $settings->identification_number,
            $issuedAt
        );

        $electronicInvoice = new ElectronicInvoice([
            'branch_id'     => $branch->id,
            'invoice_id'    => $invoice->id,
            'document_type' => $docCode,
            'clave'         => $clave['clave'],
            'consecutivo'   => $clave['consecutivo'],
            'security_code' => $clave['security_code'],
            'environment'   => $settings->effectiveEnvironment(),
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'emisor_data'   => $this->emisorSnapshot($settings),
            'receptor_data' => $this->receptorSnapshot($invoice, $letter),
            'status'        => ElectronicInvoice::STATUS_PENDING,
        ]);
        $electronicInvoice->issued_at = $issuedAt;
        $electronicInvoice->save();

        return $electronicInvoice;
    }

    /** ¿Puede un Admin transmitir este comprobante ahora mismo? */
    public function canSend(ElectronicInvoice $electronicInvoice): bool
    {
        return $this->sendBlocker($electronicInvoice) === null;
    }

    public function sendBlocker(ElectronicInvoice $electronicInvoice, bool $fromQueue = false): ?string
    {
        if (in_array($electronicInvoice->status, [
            ElectronicInvoice::STATUS_ACCEPTED,
            ElectronicInvoice::STATUS_SENT,
            ElectronicInvoice::STATUS_SENDING,
        ], true)) {
            return 'Este comprobante ya fue enviado a Hacienda.';
        }

        // 'queued' significa que ya hay un job encargado de este comprobante:
        // solo ese job puede continuar. Sin esto, dos clics seguidos al botón
        // de enviar terminan transmitiendo el mismo comprobante dos veces.
        if (!$fromQueue && $electronicInvoice->status === ElectronicInvoice::STATUS_QUEUED) {
            return 'Este comprobante ya está en la cola de envío.';
        }

        $settings = CompanySetting::instance();
        if (!$settings->isReady()) {
            return 'La facturación electrónica no está configurada (certificado o credenciales).';
        }

        return null;
    }

    /** Construye, firma y transmite un comprobante pendiente o con error. */
    public function send(ElectronicInvoice $electronicInvoice, bool $fromQueue = false): ElectronicInvoice
    {
        if ($reason = $this->sendBlocker($electronicInvoice, $fromQueue)) {
            throw new RuntimeException($reason);
        }

        $settings = CompanySetting::instance();
        $letter   = $this->letterForDocumentType($electronicInvoice->document_type);

        try {
            $this->buildAndSign($electronicInvoice, $letter);
            $this->transmit($electronicInvoice, $settings);
        } catch (\Throwable $e) {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
            $electronicInvoice->error_message = $e->getMessage();
            $electronicInvoice->save();
            Log::error('Hacienda: fallo al emitir comprobante ' . $electronicInvoice->clave . ': ' . $e->getMessage());
        }

        return $electronicInvoice->fresh();
    }

    /**
     * Manda un comprobante a la cola de envío.
     *
     * Firmar y transmitir toma segundos; hacerlo dentro del request hace que
     * un envío en bloque se caiga por timeout a media lista. El cambio de
     * estado va bajo candado para que dos clics (o dos pestañas) no encolen
     * el mismo comprobante dos veces.
     */
    public function queueSend(ElectronicInvoice $electronicInvoice): void
    {
        $queued = DB::transaction(function () use ($electronicInvoice) {
            $fresh = ElectronicInvoice::whereKey($electronicInvoice->id)->lockForUpdate()->first();

            if (!$fresh) {
                throw new RuntimeException('El comprobante ya no existe.');
            }

            if ($reason = $this->sendBlocker($fresh)) {
                throw new RuntimeException($reason);
            }

            $fresh->status = ElectronicInvoice::STATUS_QUEUED;
            $fresh->error_message = null;
            $fresh->save();

            return $fresh;
        });

        SendElectronicInvoiceJob::dispatch($queued->id);
    }

    /**
     * Encola una selección de comprobantes. Devuelve un resumen (encolados /
     * con error) para mostrar al administrador.
     *
     * @param array<int> $ids
     */
    public function sendBatch(array $ids): array
    {
        $queued = [];
        $errors = [];

        foreach (ElectronicInvoice::whereIn('id', $ids)->get() as $electronicInvoice) {
            try {
                $this->queueSend($electronicInvoice);
                $queued[] = $electronicInvoice->id;
            } catch (\Throwable $e) {
                $errors[] = ['id' => $electronicInvoice->id, 'message' => $e->getMessage()];
            }
        }

        return ['queued' => $queued, 'errors' => $errors];
    }

    /** Rearma, refirma y reenvía un comprobante rechazado o con error. */
    public function retry(ElectronicInvoice $electronicInvoice): ElectronicInvoice
    {
        $settings = CompanySetting::instance();
        if (!$settings->isReady()) {
            throw new RuntimeException('La facturación electrónica no está configurada.');
        }

        if ($electronicInvoice->status === ElectronicInvoice::STATUS_REJECTED) {
            // Una clave rechazada quedó consumida: se pide una nueva.
            $this->regenerateClave($electronicInvoice, $settings);
        }

        $electronicInvoice->status = ElectronicInvoice::STATUS_PENDING;
        $electronicInvoice->error_message = null;
        $electronicInvoice->save();

        $this->queueSend($electronicInvoice);

        return $electronicInvoice->fresh();
    }

    private function regenerateClave(ElectronicInvoice $electronicInvoice, CompanySetting $settings): void
    {
        $electronicInvoice->loadMissing('invoice.pickupBranch');
        $invoice = $electronicInvoice->invoice;
        $branch  = $invoice?->pickupBranch;
        if (!$branch) {
            return;
        }

        // Una nota conserva su tipo (02/03); solo FE/TE se recalculan según
        // si la encomienda tiene receptor identificado.
        $docCode = $electronicInvoice->isNote()
            ? $electronicInvoice->document_type
            : Catalogs::documentCode($invoice->receptorIdentificado() ? 'FE' : 'TE');
        $issuedAt = Carbon::now(config('app.timezone') ?: 'America/Costa_Rica');

        $clave = $this->claveGenerator->generate($branch, $docCode, $settings->identification_number, $issuedAt);

        $electronicInvoice->document_type = $docCode;
        $electronicInvoice->clave = $clave['clave'];
        $electronicInvoice->consecutivo = $clave['consecutivo'];
        $electronicInvoice->security_code = $clave['security_code'];
        $electronicInvoice->issued_at = $issuedAt;
        $electronicInvoice->send_attempts = 0;
        $electronicInvoice->save();
    }

    /** Consulta el estado del comprobante en Hacienda y actualiza el registro. */
    public function pollStatus(ElectronicInvoice $electronicInvoice): ElectronicInvoice
    {
        if (!in_array($electronicInvoice->status, [ElectronicInvoice::STATUS_SENT], true)) {
            return $electronicInvoice;
        }

        $settings = CompanySetting::instance();

        try {
            $response = $this->client->status($settings, $electronicInvoice->clave);
        } catch (\Throwable $e) {
            return $electronicInvoice;
        }

        if (!$response->successful()) {
            return $electronicInvoice;
        }

        $estado = $response->json('ind-estado');
        $electronicInvoice->hacienda_status = $estado;

        if ($estado === 'aceptado') {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ACCEPTED;
            $electronicInvoice->accepted_at = now();
            if ($xmlB64 = $response->json('respuesta-xml')) {
                $path = $this->storeResponseXml($electronicInvoice, base64_decode($xmlB64));
                $electronicInvoice->response_xml_path = $path;
            }
            $electronicInvoice->save();
            app(PdfGenerator::class)->generate($electronicInvoice);
            $this->sendInvoiceEmail($electronicInvoice->fresh());
        } elseif ($estado === 'rechazado') {
            $electronicInvoice->status = ElectronicInvoice::STATUS_REJECTED;
            if ($xmlB64 = $response->json('respuesta-xml')) {
                $xml = base64_decode($xmlB64);
                $electronicInvoice->response_xml_path = $this->storeResponseXml($electronicInvoice, $xml);
                $parsed = app(RejectionParser::class)->parse($xml);
                $electronicInvoice->error_message = collect($parsed['errors'] ?? [])
                    ->map(fn ($e) => $e['message'] ?? $e['description'] ?? '')
                    ->filter()
                    ->implode(' | ');
            }
            $electronicInvoice->save();
        } else {
            $electronicInvoice->save();
        }

        return $electronicInvoice;
    }

    private function letterForDocumentType(string $code): string
    {
        return match ($code) {
            '01' => 'FE',
            '02' => 'ND',
            '03' => 'NC',
            default => 'TE',
        };
    }

    private function buildAndSign(ElectronicInvoice $electronicInvoice, string $letter): void
    {
        $builder = match ($letter) {
            'FE' => new FacturaElectronicaXml($electronicInvoice),
            'NC' => new NotaCreditoXml($electronicInvoice),
            'ND' => new NotaDebitoXml($electronicInvoice),
            default => new TiqueteElectronicoXml($electronicInvoice),
        };

        $xml = $builder->build();
        $totals = $builder->totals();

        $settings = CompanySetting::instance();
        $p12 = $this->disk()->get($settings->certificate_path);
        $signed = $this->signer->sign($xml, $p12, $settings->certificate_pin);

        $yearMonth = Carbon::parse($electronicInvoice->issued_at)->format('Y-m');
        $path = "comprobantes/{$yearMonth}/{$electronicInvoice->clave}.xml";
        $this->disk()->put($path, $signed);

        $electronicInvoice->signed_xml_path = $path;
        $electronicInvoice->sub_total = $totals['venta_neta'] ?? 0;
        $electronicInvoice->total_tax = $totals['impuesto'] ?? 0;
        $electronicInvoice->total_discount = $totals['descuentos'] ?? 0;
        $electronicInvoice->total = $totals['total'] ?? 0;
        $electronicInvoice->save();
    }

    private function transmit(ElectronicInvoice $electronicInvoice, CompanySetting $settings): void
    {
        $electronicInvoice->status = ElectronicInvoice::STATUS_SENDING;
        $electronicInvoice->send_attempts = $electronicInvoice->send_attempts + 1;
        $electronicInvoice->last_attempt_at = now();
        $electronicInvoice->save();

        $xml = $this->disk()->get($electronicInvoice->signed_xml_path);

        $payload = [
            'clave'           => $electronicInvoice->clave,
            'fecha'           => Carbon::parse($electronicInvoice->issued_at)->format('Y-m-d\TH:i:sP'),
            'emisor'          => [
                'tipoIdentificacion'   => $electronicInvoice->emisor_data['identification_type'] ?? '02',
                'numeroIdentificacion' => $electronicInvoice->emisor_data['identification_number'] ?? '',
            ],
            'comprobanteXml'  => base64_encode($xml),
        ];

        if (!empty($electronicInvoice->receptor_data['numero'])) {
            $payload['receptor'] = [
                'tipoIdentificacion'   => $electronicInvoice->receptor_data['tipo'] ?? '01',
                'numeroIdentificacion' => $electronicInvoice->receptor_data['numero'],
            ];
        }

        try {
            $response = $this->client->send($settings, $payload);
        } catch (\Throwable $e) {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
            $electronicInvoice->error_message = $e->getMessage();
            $electronicInvoice->save();
            return;
        }

        if ($response->status() === 202 || $response->successful()) {
            $electronicInvoice->status = ElectronicInvoice::STATUS_SENT;
            $electronicInvoice->hacienda_status = 'procesando';
            $electronicInvoice->error_message = null;
        } elseif ($response->status() === 409) {
            // Ya existía: se adopta como enviado (idempotencia por clave).
            $electronicInvoice->status = ElectronicInvoice::STATUS_SENT;
            $electronicInvoice->hacienda_status = 'procesando';
        } else {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
            $electronicInvoice->error_message = 'Hacienda respondió ' . $response->status() . ': ' . $response->body();
        }

        $electronicInvoice->save();
    }

    private function storeResponseXml(ElectronicInvoice $electronicInvoice, string $xml): string
    {
        $yearMonth = Carbon::parse($electronicInvoice->issued_at)->format('Y-m');
        $path = "respuestas/{$yearMonth}/{$electronicInvoice->clave}-respuesta.xml";
        $this->disk()->put($path, $xml);
        return $path;
    }

    /**
     * Emite una nota de crédito (NC) o de débito (ND) contra un comprobante ya
     * aceptado por Hacienda.
     *
     * Es la única manera de revertir o corregir un comprobante: uno aceptado no
     * se borra ni se edita. La nota nace con clave y consecutivo propios (tipo
     * 03 o 02) y queda encolada para transmitirse.
     *
     * @param 'NC'|'ND' $type
     * @param array<int,array<string,mixed>>|null $lines Líneas detalladas; si
     *        se omiten, la nota va por un monto global.
     */
    public function issueNote(
        ElectronicInvoice $original,
        string $type,
        string $reason,
        ?float $amount = null,
        ?array $lines = null
    ): ElectronicInvoice {
        if (!in_array($type, ['NC', 'ND'], true)) {
            throw new RuntimeException('Tipo de nota inválido: ' . $type);
        }

        $settings = CompanySetting::instance();
        if (!$settings->isReady()) {
            throw new RuntimeException('La facturación electrónica no está configurada.');
        }

        // Solo tiene sentido corregir lo que Hacienda ya aceptó. Un comprobante
        // rechazado o sin enviar se corrige reintentándolo, no con una nota.
        if ($original->status !== ElectronicInvoice::STATUS_ACCEPTED) {
            throw new RuntimeException('Solo se puede emitir una nota sobre un comprobante aceptado por Hacienda.');
        }

        if ($original->isNote()) {
            throw new RuntimeException('No se puede emitir una nota sobre otra nota.');
        }

        $lines = array_values(array_filter($lines ?? [], fn ($line) => (float) ($line['precio'] ?? 0) > 0));

        $total = !empty($lines)
            ? round(array_sum(array_map(
                fn ($line) => (float) ($line['precio'] ?? 0) * max(0.001, (float) ($line['cantidad'] ?? 1)),
                $lines
            )), 5)
            : round(abs((float) ($amount ?? $original->total)), 5);

        if ($total <= 0) {
            throw new RuntimeException('El monto de la nota debe ser mayor que cero.');
        }

        if ($type === 'NC' && $total > round((float) $original->total, 5) + 0.00001) {
            throw new RuntimeException('Una nota de crédito no puede exceder el total del comprobante que corrige.');
        }

        $branch = $original->branch ?: $original->invoice?->pickupBranch;
        if (!$branch) {
            throw new RuntimeException('El comprobante original no tiene sucursal asociada.');
        }

        $docCode  = Catalogs::documentCode($type);
        $issuedAt = Carbon::now(config('app.timezone') ?: 'America/Costa_Rica');

        $clave = $this->claveGenerator->generate(
            $branch,
            $docCode,
            $settings->identification_number,
            $issuedAt
        );

        // La nota se emite con los datos del comprobante original, no con los
        // de hoy: si la empresa cambió de nombre o de dirección después, la
        // nota tiene que seguir cuadrando con lo que se emitió en su momento.
        $emisor = $original->emisor_data ?? $this->emisorSnapshot($settings);
        $emisor['iva_rate'] = $this->originalIvaRate($original);

        $note = new ElectronicInvoice([
            'branch_id'            => $branch->id,
            'invoice_id'           => $original->invoice_id,
            'reference_invoice_id' => $original->id,
            'reference_reason'     => $reason,
            'note_lines'           => !empty($lines) ? $lines : null,
            'document_type'        => $docCode,
            'clave'                => $clave['clave'],
            'consecutivo'          => $clave['consecutivo'],
            'security_code'        => $clave['security_code'],
            'environment'          => $settings->effectiveEnvironment(),
            'currency_code'        => $original->currency_code ?: 'CRC',
            'exchange_rate'        => $original->exchange_rate ?: 1,
            'emisor_data'          => $emisor,
            'receptor_data'        => $original->receptor_data,
            'total'                => $total,
            'status'               => ElectronicInvoice::STATUS_PENDING,
        ]);
        $note->issued_at = $issuedAt;
        $note->save();

        $this->queueSend($note);

        return $note->fresh();
    }

    /**
     * Tarifa de IVA con la que se emitió el comprobante original. Se despeja de
     * los totales que quedaron guardados —no de la configuración de hoy— para
     * que la nota use la misma tarifa aunque el impuesto haya cambiado después.
     */
    private function originalIvaRate(ElectronicInvoice $original): float
    {
        $base = (float) $original->sub_total;

        if ($base > 0 && $original->total_tax > 0) {
            return round((float) $original->total_tax / $base * 100, 2);
        }

        $configured = (float) ($original->invoice?->taxes->sum('percent') ?? 0);

        return $configured > 0 ? $configured : (float) config('hacienda.tax.iva_rate');
    }

    /**
     * Le entrega al receptor el comprobante aceptado: XML firmado, respuesta de
     * Hacienda y PDF. Un fallo de correo nunca debe tumbar la aceptación, que
     * ya ocurrió del lado de Hacienda.
     */
    private function sendInvoiceEmail(ElectronicInvoice $electronicInvoice): void
    {
        try {
            $email = $electronicInvoice->receptor_data['email']
                ?? $electronicInvoice->invoice?->recipient_email;

            if (!$email) {
                Log::info("Hacienda: comprobante {$electronicInvoice->clave} sin correo del receptor, no se envía.");
                return;
            }

            Notification::route('mail', $email)->notify(new SendElectronicInvoice($electronicInvoice));
            Log::info("Hacienda: comprobante {$electronicInvoice->clave} enviado a {$email}.");
        } catch (\Throwable $e) {
            Log::warning("Hacienda: no se pudo enviar el correo del comprobante {$electronicInvoice->clave}: {$e->getMessage()}");
        }
    }

    private function emisorSnapshot(CompanySetting $settings): array
    {
        return [
            'name'                  => $settings->name,
            'commercial_name'       => $settings->commercial_name,
            'identification_type'   => $settings->identification_type,
            'identification_number' => $settings->identification_number,
            'activity_code'         => $settings->activity_code,
            'province'              => $settings->province,
            'canton'                => $settings->canton,
            'district'              => $settings->district,
            'barrio'                => $settings->barrio,
            'others_signs'          => $settings->others_signs,
            'phone_code'            => $settings->phone_code,
            'phone'                 => $settings->phone,
            'email'                 => $settings->email,
            'default_cabys'         => $settings->default_cabys_code ?: config('hacienda.default_cabys_code'),
        ];
    }

    private function receptorSnapshot(Invoice $invoice, string $letter): array
    {
        if ($letter !== 'FE' || !$invoice->receptorIdentificado()) {
            return [];
        }

        return [
            'nombre' => $invoice->recipient_name,
            'tipo'   => $invoice->recipient_identification_type ?: '01',
            'numero' => $invoice->recipient_identification,
            'email'  => $invoice->recipient_email,
        ];
    }
}
