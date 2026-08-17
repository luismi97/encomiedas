<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Carbon\Carbon;

/**
 * Comprobante electrónico (Hacienda) emitido para una factura de encomienda.
 * Se crea en estado 'pending' apenas la factura se marca como entregada, y
 * queda en la lista de "pendientes de envío a Hacienda" hasta que un Admin
 * decide transmitirlo (individual o en bloque).
 */
class ElectronicInvoice extends Model
{
    public const STATUS_PENDING  = 'pending';
    public const STATUS_QUEUED   = 'queued';
    public const STATUS_SENDING  = 'sending';
    public const STATUS_SENT     = 'sent';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_ERROR    = 'error';

    protected $fillable = [
        'branch_id',
        'invoice_id',
        'reference_invoice_id',
        'reference_reason',
        'note_lines',
        'document_type',
        'clave',
        'consecutivo',
        'security_code',
        'environment',
        'issued_at',
        'emisor_data',
        'receptor_data',
        'currency_code',
        'exchange_rate',
        'sub_total',
        'total_tax',
        'total_discount',
        'total_other_charges',
        'total',
        'status',
        'hacienda_status',
        'signed_xml_path',
        'response_xml_path',
        'pdf_path',
        'error_message',
        'send_attempts',
        'last_attempt_at',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'issued_at' => 'datetime',
            'emisor_data' => 'array',
            'note_lines' => 'array',
            'receptor_data' => 'array',
            'last_attempt_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** El comprobante que esta nota de crédito/débito corrige o anula. */
    public function referenceInvoice(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reference_invoice_id');
    }

    /** Notas emitidas contra este comprobante. */
    public function referencedNotes(): HasMany
    {
        return $this->hasMany(self::class, 'reference_invoice_id');
    }

    public function isNote(): bool
    {
        return in_array($this->document_type, ['02', '03'], true);
    }

    /** Ya no se puede tocar: solo cabe emitir una nota contra él. */
    public function isSettled(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public function typeLabel(): string
    {
        return match ($this->document_type) {
            '01' => 'Factura Electrónica',
            '04' => 'Tiquete Electrónico',
            '02' => 'Nota de Débito',
            '03' => 'Nota de Crédito',
            default => 'Comprobante',
        };
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING  => 'Pendiente de envío',
            self::STATUS_QUEUED   => 'En cola de envío',
            self::STATUS_SENDING  => 'Enviando…',
            self::STATUS_SENT     => 'Enviado (procesando)',
            self::STATUS_ACCEPTED => 'Aceptado por Hacienda',
            self::STATUS_REJECTED => 'Rechazado por Hacienda',
            self::STATUS_ERROR    => 'Error',
            default => $this->status,
        };
    }

    protected function serializeDate(\DateTimeInterface $date): string
    {
        return Carbon::instance($date)->toIso8601String();
    }
}
