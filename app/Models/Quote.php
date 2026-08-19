<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Proforma: lo que costaría un envío, sin facturarlo.
 *
 * No consume consecutivo de ruta, no entra en los reportes de venta y no genera
 * comprobante ante Hacienda. Es un precio por escrito que el cliente puede
 * aceptar o no.
 */
class Quote extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'code', 'origin_branch_id', 'destination_branch_id', 'customer_id',
        'customer_name', 'customer_email', 'customer_phone',
        'shipment_type', 'notes',
        'subtotal', 'tax_total', 'total', 'valid_until',
        'sent_at', 'sent_to', 'invoice_id', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'total' => 'decimal:2',
            'valid_until' => 'date',
            'sent_at' => 'datetime',
        ];
    }

    /** Una proforma toca dos sedes, igual que una guía. */
    public function branchColumns(): array
    {
        return ['origin_branch_id', 'destination_branch_id'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    public function originBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'origin_branch_id');
    }

    public function destinationBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'destination_branch_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function fueEnviada(): bool
    {
        return $this->sent_at !== null;
    }

    public function fueAceptada(): bool
    {
        return $this->invoice_id !== null;
    }

    /** Vencida: el precio ya no se sostiene. */
    public function estaVencida(): bool
    {
        return $this->valid_until !== null
            && ! $this->fueAceptada()
            && $this->valid_until->endOfDay()->isPast();
    }

    public function estadoLabel(): string
    {
        return match (true) {
            $this->fueAceptada() => 'Aceptada',
            $this->estaVencida() => 'Vencida',
            $this->fueEnviada()  => 'Enviada',
            default              => 'Borrador',
        };
    }

    public function estadoBadgeClasses(): string
    {
        return match (true) {
            $this->fueAceptada() => 'bg-green-100 text-green-800 dark:bg-green-900/40 dark:text-green-200',
            $this->estaVencida() => 'bg-gray-200 text-gray-700 dark:bg-gray-700 dark:text-gray-300',
            $this->fueEnviada()  => 'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-200',
            default              => 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/40 dark:text-yellow-200',
        };
    }

    public function rutaLabel(): string
    {
        return ($this->originBranch?->prefix ?? '—') . ' → ' . ($this->destinationBranch?->prefix ?? '—');
    }

    public function scopePendientes(Builder $query): Builder
    {
        return $query->whereNull('invoice_id');
    }

    /**
     * Consecutivo propio: COT-000001.
     *
     * Se reserva bajo candado por la misma razón que el de las guías: contarlo
     * con un COUNT hace que dos cotizaciones simultáneas lean el mismo número.
     */
    public static function siguienteCodigo(): string
    {
        return DB::transaction(function () {
            $ultimo = static::withoutGlobalScopes()
                ->lockForUpdate()
                ->orderByDesc('id')
                ->value('code');

            $numero = $ultimo && preg_match('/(\d+)$/', $ultimo, $m) ? ((int) $m[1]) + 1 : 1;

            return 'COT-' . str_pad((string) $numero, 6, '0', STR_PAD_LEFT);
        });
    }
}
