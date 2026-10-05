<?php

namespace App\Livewire\Concerns;

use App\Services\Hacienda\TaxpayerLookup;

/**
 * Busca una cédula en Hacienda para autocompletar a quién se factura: nombre
 * oficial, tipo de identificación y sus actividades económicas.
 *
 * Nunca traba la guía: si Hacienda no contesta, se avisa y se sigue a mano.
 */
trait ConsultaHacienda
{
    /** @var array<int, array{code:string, description:string, principal:bool}> */
    public array $actividadesHacienda = [];

    public ?string $avisoHacienda = null;

    /**
     * @return array{status:string, name?:string, id_type?:?string, activities?:array}|null
     *         null si la identificación no tiene largo de cédula (ni se pregunta).
     */
    protected function consultarContribuyente(?string $identificacion): ?array
    {
        $this->actividadesHacienda = [];
        $this->avisoHacienda = null;

        $id = preg_replace('/\D/', '', (string) $identificacion);

        if (strlen($id) < 9 || strlen($id) > 12) {
            return null;
        }

        $resultado = app(TaxpayerLookup::class)->find($id);

        $this->avisoHacienda = match ($resultado['status']) {
            TaxpayerLookup::FOUND       => null,
            TaxpayerLookup::NOT_FOUND   => 'Hacienda no tiene registrada esa identificación. Revisá el número.',
            default                     => 'No se pudo consultar Hacienda. Completá los datos a mano.',
        };

        $this->actividadesHacienda = $resultado['activities'] ?? [];

        return $resultado;
    }

    /** La actividad principal que devolvió Hacienda, o la primera. */
    protected function actividadPrincipal(): ?string
    {
        return $this->actividadesHacienda[0]['code'] ?? null;
    }
}
