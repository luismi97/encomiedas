<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\ElectronicInvoice;
use App\Services\Hacienda\ElectronicBillingService;
use App\Notifications\CambioDeEstadoGuia;
use App\Models\GuideStatusHistory;
use App\Models\Invoice;
use App\Services\CajaService;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

/**
 * Único punto por donde una guía cambia de estado.
 *
 * Concentra tres cosas que estaban sueltas: validar que la transición sea legal,
 * dejar la bitácora, y poner las marcas de tiempo que después usan el cron de
 * desecho y la facturación electrónica.
 */
class GuideStatusService
{
    public const SOLO_ADMIN_ANULA = 'Solo un administrador puede anular guías. '
        . 'Si hay que anularla, avisale con el motivo y él la anula.';

    public const SOLO_ADMIN_DEVUELVE = 'Solo un administrador puede devolver una encomienda.';

    public const SOLO_ADMIN_DESECHA = 'Solo un administrador puede desechar una encomienda.';

    /**
     * @param  string  $source  manual | scan | system
     *
     * @throws RuntimeException si la transición no está permitida
     */
    public function cambiar(
        Invoice $guia,
        string $nuevoEstado,
        ?User $usuario = null,
        ?Branch $sede = null,
        string $source = GuideStatusHistory::SOURCE_MANUAL,
        ?string $nota = null
    ): Invoice {
        if ($guia->status === $nuevoEstado) {
            return $guia;
        }

        // Aquí y no solo en anular(): cualquier camino que llegue a «Anulado»
        // pasa por este método, y la regla no puede depender de cuál se usó.
        if ($nuevoEstado === Invoice::STATUS_CANCELLED && $usuario && ! $usuario->puedeAnular()) {
            throw new RuntimeException(self::SOLO_ADMIN_ANULA);
        }

        // Devolver y desechar son decisiones de un administrador. El sistema
        // tampoco: un desecho tiene que llevar el nombre de quien lo autorizó.
        if ($nuevoEstado === Invoice::STATUS_RETURNED) {
            if (! $usuario?->isAdmin()) {
                throw new RuntimeException(self::SOLO_ADMIN_DEVUELVE);
            }

            if (blank($guia->return_reason)) {
                throw new RuntimeException('Toda devolución necesita un motivo.');
            }
        }

        if ($nuevoEstado === Invoice::STATUS_DISPOSED) {
            if (! $usuario?->isAdmin()) {
                throw new RuntimeException(self::SOLO_ADMIN_DESECHA);
            }

            if (! $guia->yaSePuedeDesechar()) {
                throw new RuntimeException("La guía {$guia->code} no se puede desechar antes de "
                    . Invoice::MESES_ANTES_DE_DESECHAR . ' meses en destino: se puede a partir del '
                    . $guia->desechableDesde()?->format('d/m/Y') . '.');
            }
        }

        if (! $guia->puedePasarA($nuevoEstado)) {
            throw new RuntimeException($this->explicarRechazo($guia, $nuevoEstado));
        }

        $anterior = $guia->status;

        DB::transaction(function () use ($guia, $nuevoEstado, $anterior, $usuario, $sede, $source, $nota) {
            $guia->status = $nuevoEstado;
            $this->sellarTiempos($guia, $nuevoEstado);
            $guia->save();

            GuideStatusHistory::create([
                'invoice_id'  => $guia->id,
                'from_status' => $anterior,
                'to_status'   => $nuevoEstado,
                'branch_id'   => $sede?->id ?? $usuario?->branch_id,
                'user_id'     => $usuario?->id,
                'source'      => $source,
                'note'        => $nota,
                'happened_at' => now(),
            ]);
        });

        $guia = $guia->fresh();
        $this->avisarAlDestinatario($guia);

        return $guia;
    }

    /**
     * Anula una guía dejando constancia de quién y por qué.
     *
     * El motivo es obligatorio: una anulación sin explicación es exactamente lo
     * que después no se puede auditar.
     */
    public function anular(Invoice $guia, User $usuario, string $motivo): Invoice
    {
        if (! $usuario->puedeAnular()) {
            throw new RuntimeException(self::SOLO_ADMIN_ANULA);
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Toda anulación necesita un motivo.');
        }

        if (! $guia->sePuedeAnular()) {
            throw new RuntimeException("La guía {$guia->code} ya está anulada.");
        }

        $comprobante = $this->comprobanteParaAnular($guia);

        $guia->forceFill([
            'cancellation_reason' => trim($motivo),
            'cancelled_by'        => $usuario->id,
            'cancelled_at'        => now(),
        ])->save();

        $anulada = $this->cambiar(
            $guia,
            Invoice::STATUS_CANCELLED,
            $usuario,
            null,
            GuideStatusHistory::SOURCE_MANUAL,
            'Anulada: ' . trim($motivo)
        );

        // Ante Hacienda una factura aceptada no se anula: se revierte con una
        // nota de crédito por lo que quede sin acreditar.
        if ($comprobante && ($saldo = $this->saldoSinAcreditar($comprobante)) > 0) {
            try {
                app(ElectronicBillingService::class)->issueNote(
                    $comprobante,
                    'NC',
                    mb_substr('Anulación de la guía ' . $guia->code . ': ' . trim($motivo), 0, 180),
                    $saldo
                );
            } catch (\Throwable $e) {
                Log::error("Anulación de {$guia->code}: no se pudo emitir la nota de crédito: " . $e->getMessage());

                throw new RuntimeException("La guía {$guia->code} quedó anulada, pero la nota de crédito no se pudo "
                    . 'emitir (' . $e->getMessage() . '). Emitila a mano desde la guía.');
            }
        }

        return $anulada;
    }

    /**
     * El comprobante aceptado que la anulación tiene que revertir, o null.
     *
     * Lanza si Hacienda todavía no contestó: no se sabe si hará falta nota, y
     * anular igual dejaría una factura viva sin nadie que la revierta.
     */
    private function comprobanteParaAnular(Invoice $guia): ?ElectronicInvoice
    {
        $comprobante = $guia->electronicInvoice()->first();

        if (! $comprobante) {
            return null;
        }

        if (in_array($comprobante->status, [
            ElectronicInvoice::STATUS_QUEUED,
            ElectronicInvoice::STATUS_SENDING,
            ElectronicInvoice::STATUS_SENT,
        ], true)) {
            throw new RuntimeException("El comprobante de la guía {$guia->code} está en Hacienda esperando respuesta. "
                . 'Esperá a que lo acepten o rechacen y volvé a anular.');
        }

        return $comprobante->status === ElectronicInvoice::STATUS_ACCEPTED ? $comprobante : null;
    }

    /** Total del comprobante menos las notas de crédito que ya tiene (salvo rechazadas). */
    private function saldoSinAcreditar(ElectronicInvoice $comprobante): float
    {
        $acreditado = (float) ElectronicInvoice::where('reference_invoice_id', $comprobante->id)
            ->where('document_type', \App\Services\Hacienda\Catalogs::documentCode('NC'))
            ->where('status', '!=', ElectronicInvoice::STATUS_REJECTED)
            ->sum('total');

        return round((float) $comprobante->total - $acreditado, 5);
    }

    public const SOLO_ADMIN_CORRIGE = 'Solo un administrador puede corregir el estado de una guía.';

    /**
     * Corrige el estado de una guía que se movió por error.
     *
     * Es la salida para cuando alguien marcó «Entregado» la guía equivocada o
     * la pasó de largo: el ciclo normal no deja volver atrás, y sin esto el
     * único arreglo era tocar la base. Por eso tiene que ser lo contrario de un
     * atajo: solo un administrador, con motivo, y queda en la bitácora igual
     * que cualquier otro cambio.
     *
     * Salta las reglas de transición, pero no las que protegen plata y
     * comprobantes:
     *  - Anular sigue siendo anular(): lleva la nota de crédito si hace falta.
     *  - A «Entregado» no se corrige una guía con cobro pendiente: eso es una
     *    entrega de verdad y el cobro tiene que entrar a una caja.
     *  - Una anulada que ya tiene nota de crédito no revive: el comprobante
     *    quedó revertido ante Hacienda.
     *
     * Al volver atrás se borran las marcas de los estados que se deshacen
     * (fecha de llegada, de entrega, quién retiró…): si no, la guía diría que
     * se entregó estando en bodega, y el cron de desecho contaría desde una
     * llegada que no fue.
     */
    public function corregirEstado(Invoice $guia, string $nuevoEstado, User $usuario, string $motivo): Invoice
    {
        if (! $usuario->isAdmin()) {
            throw new RuntimeException(self::SOLO_ADMIN_CORRIGE);
        }

        $motivo = trim($motivo);

        if ($motivo === '') {
            throw new RuntimeException('Toda corrección de estado necesita un motivo.');
        }

        if (! array_key_exists($nuevoEstado, Invoice::STATUSES)) {
            throw new RuntimeException('Ese estado no existe.');
        }

        if ($guia->status === $nuevoEstado) {
            throw new RuntimeException("La guía {$guia->code} ya está en «" . Invoice::STATUSES[$nuevoEstado] . '».');
        }

        if ($nuevoEstado === Invoice::STATUS_CANCELLED) {
            throw new RuntimeException('Para anular usá «Anular»: si el comprobante ya fue aceptado, lleva su nota de crédito.');
        }

        if ($nuevoEstado === Invoice::STATUS_DELIVERED && ($guia->tieneCobroPendiente() || $guia->esperandoCaja())) {
            throw new RuntimeException("La guía {$guia->code} tiene un cobro pendiente: entregala con «Entregado», "
                . 'que registra el cobro en tu caja.');
        }

        if ($guia->status === Invoice::STATUS_CANCELLED && $guia->electronicNotes()->exists()) {
            throw new RuntimeException("La guía {$guia->code} se anuló con nota de crédito ante Hacienda: no se puede revivir. "
                . 'Hacé una guía nueva.');
        }

        $anterior = $guia->status;

        DB::transaction(function () use ($guia, $nuevoEstado, $anterior, $usuario, $motivo) {
            $this->deshacerMarcas($guia, $nuevoEstado);

            if ($nuevoEstado === Invoice::STATUS_RETURNED) {
                $guia->return_reason = $guia->return_reason ?: $motivo;
                $guia->returned_by = $guia->returned_by ?: $usuario->id;
            }

            $guia->status = $nuevoEstado;
            $this->sellarTiempos($guia, $nuevoEstado);
            $guia->save();

            GuideStatusHistory::create([
                'invoice_id'  => $guia->id,
                'from_status' => $anterior,
                'to_status'   => $nuevoEstado,
                'branch_id'   => $usuario->branch_id,
                'user_id'     => $usuario->id,
                'source'      => GuideStatusHistory::SOURCE_MANUAL,
                'note'        => 'Corrección de estado: ' . $motivo,
                'happened_at' => now(),
            ]);
        });

        // Sin aviso al destinatario: es arreglar un error, no un movimiento
        // del paquete, y un correo de «llegó» por una corrección confunde.
        return $guia->fresh();
    }

    /** Orden del recorrido, para saber qué marcas quedan «en el futuro». */
    private const ETAPA = [
        Invoice::STATUS_PENDING        => 0,
        Invoice::STATUS_READY          => 1,
        Invoice::STATUS_DISPATCHED     => 2,
        Invoice::STATUS_IN_TRANSIT     => 3,
        Invoice::STATUS_AT_DESTINATION => 4,
        Invoice::STATUS_NEAR_DISPOSAL  => 5,
        Invoice::STATUS_DELIVERED      => 6,
        Invoice::STATUS_DISPOSED       => 6,
        Invoice::STATUS_RETURNED       => 6,
        Invoice::STATUS_CANCELLED      => 6,
    ];

    private function deshacerMarcas(Invoice $guia, string $nuevoEstado): void
    {
        $etapa = self::ETAPA[$nuevoEstado];

        if ($etapa < self::ETAPA[Invoice::STATUS_AT_DESTINATION]) {
            $guia->arrived_at = null;
        }

        if ($nuevoEstado !== Invoice::STATUS_NEAR_DISPOSAL && $nuevoEstado !== Invoice::STATUS_DISPOSED) {
            $guia->disposal_warned_at = null;
        }

        if ($nuevoEstado !== Invoice::STATUS_DISPOSED) {
            $guia->disposed_at = null;
        }

        if ($nuevoEstado !== Invoice::STATUS_DELIVERED) {
            // La entrega no fue: quién retiró y su firma tampoco. Lo cobrado al
            // entregar (collected_at) se queda: esa plata sí entró a una caja.
            $guia->delivered_at = null;
            $guia->received_by_name = null;
            $guia->received_by_identification = null;
            $guia->delivery_signature = null;
        }

        if ($nuevoEstado !== Invoice::STATUS_RETURNED) {
            $guia->returned_at = null;
            $guia->return_reason = null;
            $guia->returned_by = null;
        }

        if ($guia->status === Invoice::STATUS_CANCELLED) {
            $guia->cancellation_reason = null;
            $guia->cancelled_by = null;
            $guia->cancelled_at = null;
        }
    }

    /**
     * Devuelve la encomienda al remitente. Solo el administrador, con motivo.
     */
    public function devolver(Invoice $guia, User $usuario, string $motivo): Invoice
    {
        if (! $usuario->isAdmin()) {
            throw new RuntimeException(self::SOLO_ADMIN_DEVUELVE);
        }

        if (trim($motivo) === '') {
            throw new RuntimeException('Toda devolución necesita un motivo.');
        }

        if (! $guia->puedePasarA(Invoice::STATUS_RETURNED)) {
            throw new RuntimeException($this->explicarRechazo($guia, Invoice::STATUS_RETURNED));
        }

        $guia->forceFill([
            'return_reason' => trim($motivo),
            'returned_by'   => $usuario->id,
        ])->save();

        return $this->cambiar(
            $guia,
            Invoice::STATUS_RETURNED,
            $usuario,
            null,
            GuideStatusHistory::SOURCE_MANUAL,
            'Devuelta: ' . trim($motivo)
        );
    }

    /**
     * Entrega con evidencia de quién retiró.
     *
     * La firma llega como data URI del canvas del navegador; se valida que sea
     * una imagen y no cualquier cadena, porque viene del cliente.
     *
     * $facturarA es para quien pide factura con cédula recién al retirar: el
     * comprobante se crea al pasar a entregada, así que se guarda antes. Solo
     * cambia a quién se factura, nunca los montos.
     *
     * @param  array{nombre:string, tipo:string, numero:string, email:?string, actividad:?string}|null  $facturarA
     */
    public function entregar(
        Invoice $guia,
        User $usuario,
        string $nombreQuienRetira,
        ?string $identificacion = null,
        ?string $firmaDataUri = null,
        ?array $facturarA = null
    ): Invoice {
        if (trim($nombreQuienRetira) === '') {
            throw new RuntimeException('Hay que registrar el nombre de quien retira la encomienda.');
        }

        // Antes de mover nada: si el flete se cobra aquí y no hay caja abierta,
        // el dinero no entraría a ningún arqueo. Se registraba solo un aviso en
        // el log y la plata desaparecía sin dejar rastro.
        if ($guia->esperandoCaja()) {
            throw new RuntimeException("La guía {$guia->code} es de contado y todavía no se cobró en caja: "
                . 'cobrala en caja antes de entregar el paquete.');
        }

        // Quien no cobra no tiene caja: el cobro lo hace el cajero desde la
        // caja («Por cobrar en caja») y recién ahí se entrega.
        if ($guia->tieneCobroPendiente() && ! $usuario->puedeCobrar()) {
            throw new RuntimeException(
                'Esta guía es POR COBRAR (₡' . number_format((float) $guia->total, 2) . '). Tu usuario no cobra: '
                . 'que el cliente pague en caja y después entregá el paquete.'
            );
        }

        if ($guia->tieneCobroPendiente() && ! $this->cajaDeDestino($guia, $usuario)) {
            throw new RuntimeException(
                'Esta guía es POR COBRAR (₡' . number_format((float) $guia->total, 2) . ') y no tenés una caja '
                . 'abierta en esta sede: el cobro no entraría a ningún arqueo. Abrí tu caja y volvé a entregar.'
            );
        }

        $firma = null;

        if ($firmaDataUri && preg_match('#^data:image/(png|jpeg);base64,[A-Za-z0-9+/=]+$#', $firmaDataUri)) {
            $firma = $firmaDataUri;
        }

        $nota = 'Retirada por ' . trim($nombreQuienRetira);

        if ($facturarA) {
            // Con el comprobante ya creado, cambiar la guía no cambia lo que
            // se le manda a Hacienda: avisar en vez de fingir que se aplicó.
            if ($guia->electronicInvoice()->exists()) {
                throw new RuntimeException("La guía {$guia->code} ya tiene comprobante electrónico: "
                    . 'no se puede cambiar a factura con cédula desde la entrega.');
            }

            $guia->forceFill(CorreccionDeFactura::columnas($facturarA));

            $nota .= ' · pidió factura a nombre de ' . $facturarA['nombre'] . ' (' . $facturarA['numero'] . ')';
        }

        $guia->forceFill([
            'received_by_name'           => trim($nombreQuienRetira),
            'received_by_identification' => $identificacion ? preg_replace('/\D/', '', $identificacion) : null,
            'delivery_signature'         => $firma,
        ])->save();

        $entregada = $this->cambiar(
            $guia,
            Invoice::STATUS_DELIVERED,
            $usuario,
            null,
            GuideStatusHistory::SOURCE_MANUAL,
            $nota
        );

        $this->cobrarSiEstabaPorCobrar($entregada, $usuario);

        return $entregada;
    }

    /**
     * El flete «por cobrar» se cobra al entregar, en la caja de destino.
     *
     * Esa plata nunca pasó por el mostrador de origen: registrarla allá habría
     * dejado el arqueo de origen con un ingreso que no estaba en la gaveta.
     */
    /** Turno abierto en la sede que entrega, que es donde entra este dinero. */
    private function cajaDeDestino(Invoice $guia, User $usuario)
    {
        // Propio: el cobro es de quien entrega y responde por su arqueo.
        return app(CajaService::class)->sesionPropiaAbierta($usuario, $guia->delivery_branch_id);
    }

    private function cobrarSiEstabaPorCobrar(Invoice $guia, User $usuario): void
    {
        if (! $guia->tieneCobroPendiente()) {
            return;
        }

        // La caja se comprobó antes de entregar: si faltara acá, algo cambió
        // entre medio y es preferible saberlo que perder el cobro.
        if (! $sesion = $this->cajaDeDestino($guia, $usuario)) {
            Log::error("Entrega de {$guia->code}: la caja de destino se cerró durante la entrega "
                . 'y el cobro por cobrar quedó sin registrar.');

            return;
        }

        app(CajaService::class)->registrarCobro($guia, $usuario, $sesion);

        $guia->forceFill(['collected_at' => now()])->save();
    }

    /**
     * Avisa por correo cuando el cambio le pide algo al destinatario.
     *
     * Falla en silencio a propósito: un correo que no sale no puede impedir que
     * el paquete cambie de estado, porque el estado ya cambió físicamente.
     */
    private function avisarAlDestinatario(Invoice $guia): void
    {
        if (! CambioDeEstadoGuia::aplicaA($guia->status) || blank($guia->recipient_email)) {
            return;
        }

        try {
            Notification::route('mail', $guia->recipient_email)
                ->notify(new CambioDeEstadoGuia($guia));
        } catch (\Throwable $e) {
            Log::warning('No se pudo avisar del estado de ' . $guia->code . ': ' . $e->getMessage());
        }
    }

    /**
     * Marcas de tiempo del estado. arrived_at es la que más pesa: de ella se
     * cuentan los días para próximo-a-desecho.
     */
    private function sellarTiempos(Invoice $guia, string $estado): void
    {
        match ($estado) {
            Invoice::STATUS_AT_DESTINATION => $guia->arrived_at = $guia->arrived_at ?? now(),
            Invoice::STATUS_DELIVERED      => $guia->delivered_at = $guia->delivered_at ?? now(),
            Invoice::STATUS_RETURNED       => $guia->returned_at = $guia->returned_at ?? now(),
            Invoice::STATUS_NEAR_DISPOSAL  => $guia->disposal_warned_at = $guia->disposal_warned_at ?? now(),
            Invoice::STATUS_DISPOSED       => $guia->disposed_at = $guia->disposed_at ?? now(),
            default                        => null,
        };
    }

    /** Deja la primera fila de la bitácora al crear la guía. */
    public function registrarCreacion(Invoice $guia, ?User $usuario = null): void
    {
        GuideStatusHistory::create([
            'invoice_id'  => $guia->id,
            'from_status' => null,
            'to_status'   => $guia->status,
            'branch_id'   => $guia->pickup_branch_id,
            'user_id'     => $usuario?->id ?? $guia->created_by,
            'source'      => GuideStatusHistory::SOURCE_MANUAL,
            'note'        => $guia->offline_reference
                ? "Encomienda recibida en sede origen sin conexión ({$guia->offline_reference})."
                : 'Encomienda recibida en sede origen.',
            // La de la guía y no now(): una hecha sin conexión se recibió
            // cuando se recibió, no cuando volvió el internet.
            'happened_at' => $guia->created_at ?? now(),
        ]);
    }

    /** Un mensaje que diga qué se puede hacer, no solo que no se pudo. */
    private function explicarRechazo(Invoice $guia, string $nuevoEstado): string
    {
        $actual  = Invoice::STATUSES[$guia->status] ?? $guia->status;
        $destino = Invoice::STATUSES[$nuevoEstado] ?? $nuevoEstado;

        if ($guia->estaCerrada()) {
            return "La guía {$guia->code} está en «{$actual}», que es un estado final: ya no admite más cambios.";
        }

        $posibles = implode(', ', $guia->siguientesEstados());

        return "La guía {$guia->code} no puede pasar de «{$actual}» a «{$destino}». Desde aquí solo puede ir a: {$posibles}.";
    }
}
