<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Builder;
use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Caja física de una sede. Una sede puede tener varias.
 *
 * La impresora de recibos y etiquetas es de la caja y no de la sede: cada
 * mostrador tiene la suya, y en una misma sucursal puede haber una térmica en
 * uno y una de matriz de puntos en otro.
 */
class CashRegister extends Model
{
    use BelongsToCompany, BelongsToBranch;

    protected $fillable = ['branch_id', 'name', 'receipt_paper_width', 'receipt_printer', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(CashSession::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** El turno abierto, si hay alguno. */
    public function sesionAbierta(): ?CashSession
    {
        return $this->sessions()->where('status', CashSession::STATUS_OPEN)->latest('id')->first();
    }

    public function estaAbierta(): bool
    {
        return $this->sesionAbierta() !== null;
    }

    /** Turnos ya cerrados: son documentos contables y no se borran. */
    public function tieneHistorial(): bool
    {
        return $this->sessions()->exists();
    }

    /** «Mostrador 2 — Limón Centro», para los selectores. */
    public function nombreCompleto(): string
    {
        return $this->name . ' — ' . ($this->branch?->name ?? 'sin sede');
    }

    /**
     * Anchos de rollo del mercado, en milímetros. 58 y 80 son los de las
     * térmicas; 76 es el de las de impacto (Epson TM-U220 y similares).
     */
    public const PAPER_WIDTHS = [58, 76, 80];

    public const IMPRESORA_TERMICA = 'termica';
    public const IMPRESORA_MATRIZ = 'matriz';

    public const PRINTER_TYPES = [
        self::IMPRESORA_TERMICA => 'Térmica',
        self::IMPRESORA_MATRIZ  => 'Matriz de puntos (impacto)',
    ];

    /**
     * Lo que de verdad imprime una de impacto en cada rollo, en milímetros. El
     * cabezal no llega a los bordes: en rollo de 76 la TM-U220 imprime 63,4 mm
     * centrados, y lo que se diseña más ancho sale cortado a la derecha —justo
     * donde van los montos— o el navegador lo encoge hasta que no se lee.
     */
    public const ANCHO_IMPRIMIBLE_MATRIZ = [58 => 45, 76 => 63, 80 => 68];

    /** Un valor raro cae a térmica, que era lo único que había. */
    public function receiptPrinterType(): string
    {
        return array_key_exists((string) $this->receipt_printer, self::PRINTER_TYPES)
            ? $this->receipt_printer
            : self::IMPRESORA_TERMICA;
    }

    public function imprimeEnMatriz(): bool
    {
        return $this->receiptPrinterType() === self::IMPRESORA_MATRIZ;
    }

    /**
     * Ancho del rollo saneado: una fila vieja puede traer null o un valor raro,
     * y una etiqueta con el ancho equivocado sale cortada.
     */
    public function receiptPaperWidthMm(): int
    {
        $ancho = (int) ($this->receipt_paper_width ?? 0);

        return in_array($ancho, self::PAPER_WIDTHS, true) ? $ancho : 80;
    }

    /** «Matriz de puntos · 76 mm», para el listado de cajas. */
    public function impresoraLabel(): string
    {
        return self::PRINTER_TYPES[$this->receiptPrinterType()] . ' · ' . $this->receiptPaperWidthMm() . ' mm';
    }
}
