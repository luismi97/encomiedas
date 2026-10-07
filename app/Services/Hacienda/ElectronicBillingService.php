<?php

namespace App\Services\Hacienda;

use App\Jobs\SendElectronicInvoiceJob;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Notifications\SendElectronicInvoice;
use App\Support\CompanyContext;
use Carbon\Carbon;
use Illuminate\Http\Client\Response;
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
 * A diferencia de un POS, aquí el envío NUNCA es automático: al recibir el
 * paquete solo se reserva el consecutivo —para que el recibo del cliente lo
 * lleve— y el comprobante queda en la lista de "pendientes de envío a
 * Hacienda" (ver queueForInvoice y RegistroDeGuia). Si no se pudo reservar
 * entonces, se reserva al entregar. Un administrador decide después, uno por
 * uno o en bloque, cuáles transmitir; la fecha de emisión es la del envío
 * (ver fecharAlEmitir).
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
     * Reserva la clave y deja el comprobante en estado 'pending' (lista de
     * pendientes de envío). Se llama al recibir el paquete y, por si entonces
     * no se pudo, otra vez al entregarlo. Idempotente: si ya existe un
     * comprobante para la factura, no hace nada.
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

        // Una guía anulada no se factura. La nota de crédito sí sale: es lo que
        // revierte el comprobante cuando la anulación llegó después.
        if (!$electronicInvoice->isNote()
            && $electronicInvoice->invoice()->value('status') === Invoice::STATUS_CANCELLED) {
            return 'La guía está anulada: este comprobante no se envía.';
        }

        $settings = CompanySetting::instance();
        if (!$settings->isReady()) {
            return 'La facturación electrónica no está configurada (certificado o credenciales).';
        }

        // Sin emisor_data el payload sale con numeroIdentificacion vacio y
        // Hacienda responde 400 tras haber quemado el consecutivo.
        $emisorCedula = $electronicInvoice->emisor_data['identification_number'] ?? null;

        if (blank($emisorCedula)) {
            return 'Este comprobante no tiene datos del emisor. No se generó desde una encomienda real '
                . '(por ejemplo, viene de los datos de demostración) y Hacienda lo rechazaría.';
        }

        // La cedula del emisor va DENTRO de la clave: si no coincide con la
        // configurada, la clave es de otro emisor y Hacienda nunca la aceptaria.
        if ($emisorCedula !== $settings->identification_number) {
            return 'La cédula del emisor de este comprobante (' . $emisorCedula . ') no coincide con la '
                . 'configurada (' . $settings->identification_number . '). La clave se generó con otro emisor: '
                . 'hay que emitirlo de nuevo.';
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
            $this->fecharAlEmitir($electronicInvoice);
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

        // El snapshot del emisor se toma al crear el comprobante y se congela,
        // que es lo correcto para uno aceptado: debe conservar lo que realmente
        // se envió. Pero en un reintento es al revés: si el rechazo fue por los
        // datos del emisor (actividad económica, ubicación), corregirlos en la
        // configuración no servía de nada porque el comprobante seguía llevando
        // los viejos. Aquí nunca se llega con uno aceptado.
        $this->refreshEmisorSnapshot($electronicInvoice, $settings);

        $electronicInvoice->status = ElectronicInvoice::STATUS_PENDING;
        $electronicInvoice->error_message = null;
        $electronicInvoice->rejection_details = null;
        $electronicInvoice->rejected_at = null;
        $electronicInvoice->save();

        $this->queueSend($electronicInvoice);

        return $electronicInvoice->fresh();
    }

    /**
     * Rehace un comprobante que nunca llegó a Hacienda porque cambió a quién
     * se factura (por ejemplo, de tiquete a factura con cédula).
     *
     * Solo pendientes o rechazados: uno pendiente no se transmitió, y uno
     * rechazado ya tenía la clave consumida. Pide clave nueva porque el tipo
     * de documento (FE/TE) va dentro de la clave; el consecutivo del tiquete
     * queda sin usar, y Hacienda no rechaza huecos.
     */
    public function rehacerPorCambioDeReceptor(ElectronicInvoice $electronicInvoice): ElectronicInvoice
    {
        if (! in_array($electronicInvoice->status, [ElectronicInvoice::STATUS_PENDING, ElectronicInvoice::STATUS_REJECTED], true)) {
            throw new RuntimeException('El comprobante ya se envió a Hacienda («' . $electronicInvoice->statusLabel() . '»): '
                . 'no se puede rehacer. Si fue aceptado, corregilo con una nota de crédito.');
        }

        $settings = CompanySetting::instance();

        // La guía recién cambió: sin esto se usaría la copia ya cargada.
        $electronicInvoice->unsetRelation('invoice');

        $this->regenerateClave($electronicInvoice, $settings);
        $this->refreshEmisorSnapshot($electronicInvoice, $settings);

        $electronicInvoice->forceFill([
            'status'            => ElectronicInvoice::STATUS_PENDING,
            'error_message'     => null,
            'rejection_details' => null,
            'rejected_at'       => null,
        ])->save();

        return $electronicInvoice->fresh();
    }

    /** Vuelve a fotografiar al emisor y al receptor con lo configurado hoy. */
    private function refreshEmisorSnapshot(ElectronicInvoice $electronicInvoice, CompanySetting $settings): void
    {
        $electronicInvoice->emisor_data = $this->emisorSnapshot($settings);

        $electronicInvoice->loadMissing('invoice');

        if ($invoice = $electronicInvoice->invoice) {
            $letter = $this->letterForDocumentType($electronicInvoice->document_type);
            $electronicInvoice->receptor_data = $this->receptorSnapshot($invoice, $letter);
        }

        $electronicInvoice->save();
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

        // Hacienda no conoce la clave: el envio se dio por bueno pero el
        // documento no esta. Se devuelve a error para poder reintentarlo con la
        // misma clave, que sigue sin consumirse. Se le da holgura porque la
        // consulta puede ir por delante de la recepcion recien hecha.
        if ($response->status() === 404) {
            if ($electronicInvoice->last_attempt_at
                && $electronicInvoice->last_attempt_at->lt(now()->subMinutes(15))) {
                $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
                $electronicInvoice->hacienda_status = null;
                $electronicInvoice->error_message = 'Hacienda no tiene registrada la clave: el comprobante no llegó. Reintente el envío.';
                $electronicInvoice->save();
            }

            return $electronicInvoice;
        }

        if (!$response->successful()) {
            return $electronicInvoice; // sigue en proceso o fallo transitorio
        }

        $this->applyStatusResponse($electronicInvoice, $response);

        return $electronicInvoice;
    }

    /**
     * Le pregunta a Hacienda por la clave y sincroniza el estado local.
     *
     * @return bool|null true  = Hacienda la tiene (el estado quedo actualizado)
     *                   false = no la conoce (404), la clave sigue libre
     *                   null  = no se pudo averiguar; NO asumir ninguna de las
     *                           dos, hay que reintentar mas tarde
     */
    public function reconcile(ElectronicInvoice $electronicInvoice): ?bool
    {
        $settings = CompanySetting::instance();

        if (!$settings->isReady()) {
            return null;
        }

        try {
            $response = $this->client->status($settings, $electronicInvoice->clave);
        } catch (\Throwable $e) {
            Log::warning('Hacienda: no se pudo consultar la clave ' . $electronicInvoice->clave . ': ' . $e->getMessage());

            return null;
        }

        if ($response->status() === 404) {
            return false; // Hacienda nunca la recibio
        }

        if (!$response->successful()) {
            return null;
        }

        $this->applyStatusResponse($electronicInvoice, $response);

        return true;
    }

    /** Vuelca en el comprobante la respuesta de consulta de estado. */
    private function applyStatusResponse(ElectronicInvoice $electronicInvoice, Response $response): void
    {
        $estado = strtolower((string) $response->json('ind-estado'));
        $electronicInvoice->hacienda_status = $estado;

        $xml = ($b64 = $response->json('respuesta-xml')) ? base64_decode($b64) : null;

        if ($xml !== null) {
            $electronicInvoice->response_xml_path = $this->storeResponseXml($electronicInvoice, $xml);
        }

        if ($estado === 'aceptado') {
            $electronicInvoice->status = ElectronicInvoice::STATUS_ACCEPTED;
            $electronicInvoice->accepted_at = now();
            $electronicInvoice->error_message = null;
            $electronicInvoice->rejection_details = null;
            $electronicInvoice->rejected_at = null;
            $electronicInvoice->save();

            app(PdfGenerator::class)->generate($electronicInvoice);
            $this->sendInvoiceEmail($electronicInvoice->fresh());
        } elseif ($estado === 'rechazado') {
            $this->applyRejection($electronicInvoice, $xml);
        } else {
            // procesando / recibido: el documento esta en Hacienda, se sigue
            // desde hacienda:poll en vez de darlo por fallido.
            $electronicInvoice->status = ElectronicInvoice::STATUS_SENT;
            $electronicInvoice->error_message = null;
            $electronicInvoice->save();
        }
    }

    /**
     * Vuelca un rechazo en el comprobante conservando la estructura.
     *
     * El detalle se guarda entero (codigo + descripcion + mensaje por error)
     * porque es lo unico que dice QUE corregir; error_message queda como
     * resumen de una linea para los listados.
     */
    private function applyRejection(ElectronicInvoice $electronicInvoice, ?string $xml): void
    {
        $electronicInvoice->status = ElectronicInvoice::STATUS_REJECTED;
        $electronicInvoice->rejected_at = now();

        $parsed = $xml !== null ? app(RejectionParser::class)->parse($xml) : null;

        if ($parsed && !empty($parsed['errors'])) {
            $electronicInvoice->rejection_details = $parsed;
            $electronicInvoice->error_message = collect($parsed['errors'])
                ->map(fn ($e) => trim((string) ($e['description'] ?? '')) ?: trim((string) ($e['message'] ?? '')))
                ->filter()
                ->implode(' | ');
        } else {
            $electronicInvoice->rejection_details = null;
            $electronicInvoice->error_message = 'Comprobante rechazado por Hacienda. Consulte el XML de respuesta para el detalle.';
        }

        $electronicInvoice->save();

        Log::warning('Hacienda: comprobante rechazado ' . $electronicInvoice->clave . ': ' . $electronicInvoice->error_message);
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

    /**
     * Hacienda no acepta una FechaEmision anterior a la generación del
     * comprobante (solo con situación «sin internet», que no es el caso). Y el
     * comprobante se reserva al recibir el paquete pero sale cuando el
     * administrador decide, a veces días después: se fecha aquí, justo antes de
     * firmarlo. La fecha va también dentro de la clave; el consecutivo
     * reservado no cambia.
     */
    private function fecharAlEmitir(ElectronicInvoice $electronicInvoice): void
    {
        if ($electronicInvoice->claveEsDefinitiva()) {
            return;
        }

        $issuedAt = Carbon::now(config('app.timezone') ?: 'America/Costa_Rica');

        $electronicInvoice->clave = $this->claveGenerator->conFecha($electronicInvoice->clave, $issuedAt);
        $electronicInvoice->issued_at = $issuedAt;
        $electronicInvoice->save();
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
            // Un timeout NO significa que el documento no llego: Hacienda pudo
            // recibirlo y estar procesandolo. Marcarlo como error sin preguntar
            // invita a reenviarlo y a tener dos comprobantes por una encomienda.
            $this->settleUnknownDelivery($electronicInvoice, 'No se pudo confirmar el envío: ' . $e->getMessage());

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

    /**
     * Cierra una transmision que quedo en duda: primero le pregunta a Hacienda
     * y solo la da por fallida si confirma que nunca la recibio (o si no se
     * pudo averiguar).
     */
    private function settleUnknownDelivery(ElectronicInvoice $electronicInvoice, string $message): void
    {
        if ($this->reconcile($electronicInvoice) === true) {
            return; // llego: reconcile() ya guardo el estado real
        }

        $electronicInvoice->status = ElectronicInvoice::STATUS_ERROR;
        $electronicInvoice->error_message = $message;
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
     *
     * Además va una copia al correo de la empresa registrado en sus datos de
     * Hacienda: es el buzón donde la oficina archiva lo emitido y desde donde
     * se lo pasa al contador. Sin la copia, la única forma de tener el PDF era
     * entrar a cada guía a descargarlo.
     */
    private function sendInvoiceEmail(ElectronicInvoice $electronicInvoice): void
    {
        $email = $this->correoDelReceptor($electronicInvoice);

        if ($email) {
            $this->notificar($electronicInvoice, $email);
        } else {
            Log::info("Hacienda: comprobante {$electronicInvoice->clave} sin correo del receptor, no se envía.");
        }

        $empresa = $this->correoDeLaEmpresa($electronicInvoice);

        if ($empresa && strcasecmp($empresa, (string) $email) !== 0) {
            $this->notificar($electronicInvoice, $empresa);
        }
    }

    private function notificar(ElectronicInvoice $electronicInvoice, string $email): void
    {
        try {
            Notification::route('mail', $email)->notify(new SendElectronicInvoice($electronicInvoice));
            Log::info("Hacienda: comprobante {$electronicInvoice->clave} enviado a {$email}.");
        } catch (\Throwable $e) {
            Log::warning("Hacienda: no se pudo enviar el correo del comprobante {$electronicInvoice->clave} a {$email}: {$e->getMessage()}");
        }
    }

    /**
     * A quién le corresponde el comprobante por correo.
     *
     * Con receptor identificado el correo es el suyo, aunque falte: caer al del
     * destinatario le mandaría la factura del remitente a otra persona. El
     * tiquete, sin receptor, va al destinatario.
     */
    public function correoDelReceptor(ElectronicInvoice $electronicInvoice): ?string
    {
        $receptor = $electronicInvoice->receptor_data ?? [];

        $email = ! empty($receptor['numero'])
            ? ($receptor['email'] ?? null)
            : $electronicInvoice->invoice?->recipient_email;

        return filled($email) ? trim($email) : null;
    }

    /**
     * El correo de la empresa en sus datos de Hacienda.
     *
     * El vigente y no el de la foto del comprobante: si la oficina cambia de
     * buzón, las copias tienen que llegar al nuevo desde ese momento.
     */
    private function correoDeLaEmpresa(ElectronicInvoice $electronicInvoice): ?string
    {
        // Sin empresa en contexto instance() podría crear una fila vacía o
        // lanzar: ahí se usa la foto que guardó el comprobante.
        $email = CompanyContext::hay()
            ? CompanySetting::query()->value('email')
            : null;

        $email = $email ?: ($electronicInvoice->emisor_data['email'] ?? null);

        return filled($email) ? trim($email) : null;
    }

    /**
     * Vuelve a mandar un comprobante ya aceptado, al cliente o a otro correo.
     *
     * Para cuando el cliente no lo encuentra, lo borró o pide que vaya a su
     * contador. Se manda en el acto y no por la cola: quien lo pide está
     * esperando en la pantalla y tiene que enterarse ahí si el correo falló.
     *
     * El PDF se rehace antes de adjuntarlo, para que salga con el formato
     * vigente aunque el comprobante sea de antes de un cambio.
     *
     * @throws RuntimeException si no está aceptado o el correo no es válido
     */
    public function reenviarCorreo(ElectronicInvoice $electronicInvoice, string $email): void
    {
        $email = trim($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('El correo «' . $email . '» no es válido.');
        }

        if ($electronicInvoice->status !== ElectronicInvoice::STATUS_ACCEPTED) {
            throw new RuntimeException('Solo se puede reenviar un comprobante aceptado por Hacienda. Este está «'
                . $electronicInvoice->statusLabel() . '».');
        }

        if ($electronicInvoice->signed_xml_path && $this->disk()->exists($electronicInvoice->signed_xml_path)) {
            try {
                app(PdfGenerator::class)->generate($electronicInvoice);
            } catch (\Throwable $e) {
                // Sin PDF nuevo se manda con el que haya: el XML firmado es el
                // documento con validez y ese sí está.
                Log::warning("Hacienda: no se pudo rehacer el PDF de {$electronicInvoice->clave} al reenviar: {$e->getMessage()}");
            }
        }

        try {
            Notification::route('mail', $email)->notifyNow(new SendElectronicInvoice($electronicInvoice->fresh()));
        } catch (\Throwable $e) {
            Log::warning("Hacienda: falló el reenvío de {$electronicInvoice->clave} a {$email}: {$e->getMessage()}");

            throw new RuntimeException('No se pudo enviar el correo: ' . $e->getMessage());
        }

        Log::info("Hacienda: comprobante {$electronicInvoice->clave} reenviado a {$email}.");
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

        // Destinatario, remitente u otra persona: lo decide la guía.
        return $invoice->receptorDeFactura();
    }
}
