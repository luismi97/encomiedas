<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Support\BusquedaDeTexto;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Remitentes y destinatarios registrados.
 *
 * Hasta ahora el remitente y el destinatario eran texto libre en cada guía, así
 * que no había forma de acumular envíos por cliente ni de facturar a crédito.
 */
class Customer extends Model
{
    use BelongsToCompany, HasFactory;

    public const PAYMENT_CASH   = 'cash';
    public const PAYMENT_CREDIT = 'credit';

    public const PAYMENT_CONDITIONS = [
        self::PAYMENT_CASH   => 'Contado',
        self::PAYMENT_CREDIT => 'Crédito',
    ];

    /** Mismo catálogo del receptor en Hacienda. */
    public const IDENTIFICATION_TYPES = [
        '01' => 'Física',
        '02' => 'Jurídica',
        '03' => 'DIMEX',
        '04' => 'NITE',
    ];

    protected $fillable = [
        'name',
        'commercial_name',
        'identification_type',
        'identification',
        'activity_code',
        'email',
        'phone',
        'address',
        'branch_id',
        'payment_condition',
        'credit_limit',
        'credit_cutoff_day',
        'notes',
        'is_active',
        'tax_exempt',
        'exemption_document_type',
        'exemption_document_type_other',
        'exemption_number',
        'exemption_institution',
        'exemption_institution_other',
        'exemption_article',
        'exemption_inciso',
        'exemption_issued_at',
        'exemption_expires_at',
        'exemption_rate',
        'exemption_cabys',
    ];

    protected function casts(): array
    {
        return [
            'is_active'    => 'boolean',
            'credit_limit' => 'decimal:2',
            'tax_exempt'   => 'boolean',
            'exemption_issued_at'  => 'date',
            'exemption_expires_at' => 'date',
            'exemption_rate'       => 'decimal:2',
            'exemption_cabys'      => 'array',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeCredit(Builder $query): Builder
    {
        return $query->where('payment_condition', self::PAYMENT_CREDIT);
    }

    /**
     * Buscador de clientes: nombre, nombre comercial, cédula, correo o teléfono.
     *
     * Los datos de contacto se buscan por prefijo —una cédula o un teléfono se
     * digitan desde el principio, nunca por la mitad— y el nombre por índice de
     * texto completo, que es lo único que aguanta una cartera grande.
     */
    public function scopeBuscar(Builder $query, ?string $termino): Builder
    {
        $termino = trim((string) $termino);

        if (! BusquedaDeTexto::esBuscable($termino)) {
            return $query;
        }

        return $query->where(function (Builder $q) use ($termino) {
            $q->where('identification', 'like', $termino . '%')
                ->orWhere('phone', 'like', $termino . '%')
                ->orWhere('email', 'like', $termino . '%');

            BusquedaDeTexto::agregar($q, ['name', 'commercial_name'], $termino);
        });
    }

    public function isCredit(): bool
    {
        return $this->payment_condition === self::PAYMENT_CREDIT;
    }

    public function paymentConditionLabel(): string
    {
        return self::PAYMENT_CONDITIONS[$this->payment_condition] ?? self::PAYMENT_CONDITIONS[self::PAYMENT_CASH];
    }

    public function identificationTypeLabel(): ?string
    {
        return self::IDENTIFICATION_TYPES[$this->identification_type] ?? null;
    }

    /**
     * ¿Se le puede emitir Factura Electrónica?
     *
     * Hacienda exige receptor identificado; sin cédula el comprobante tiene que
     * salir como Tiquete Electrónico.
     */
    public function puedeFacturaElectronica(): bool
    {
        return filled($this->identification) && filled($this->identification_type);
    }

    /**
     * ¿Hay que facturarle exonerado? Marcado y con el número de autorización:
     * sin el número no hay nada que declarar en el nodo Exoneracion.
     */
    public function estaExonerado(): bool
    {
        return $this->tax_exempt && filled($this->exemption_number);
    }

    /** La exoneración ya venció a esa fecha (hoy, si no se dice). */
    public function exoneracionVencida(?\Carbon\CarbonInterface $fecha = null): bool
    {
        return $this->exemption_expires_at !== null
            && $this->exemption_expires_at->lt(($fecha ?? now())->copy()->startOfDay());
    }

    /**
     * Lo que la guía copia para declarar la exoneración.
     *
     * Se copia y no se referencia, como el resto de los datos del cliente: la
     * guía es un documento, y si la exoneración vence o la cambian después,
     * la factura tiene que seguir declarando la que estaba vigente.
     *
     * @return array<string,mixed>
     */
    public function datosDeExoneracion(): array
    {
        return [
            'tipo'             => $this->exemption_document_type,
            'tipo_otro'        => $this->exemption_document_type_other,
            'numero'           => $this->exemption_number,
            'institucion'      => $this->exemption_institution,
            'institucion_otro' => $this->exemption_institution_other,
            'articulo'         => $this->exemption_article,
            'inciso'           => $this->exemption_inciso,
            'fecha_emision'    => $this->exemption_issued_at?->toDateString(),
            'vence'            => $this->exemption_expires_at?->toDateString(),
            'tarifa'           => (float) ($this->exemption_rate ?? 13),
            'identificacion'   => $this->identification,
            'cabys'            => $this->exemption_cabys ?: [],
        ];
    }

    /** Cómo se muestra en listados y buscadores. */
    public function displayName(): string
    {
        return $this->identification
            ? $this->name . ' · ' . $this->identification
            : $this->name;
    }
}
