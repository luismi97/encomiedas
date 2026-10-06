<?php

namespace App\Livewire\Concerns;

use App\Models\Customer;
use App\Models\Invoice;
use App\Services\Hacienda\Catalogs;
use Illuminate\Validation\Rule;

/**
 * A quién se factura una guía ya creada: los datos del receptor de la Factura
 * Electrónica, sin tocar ningún monto.
 *
 * Lo usan dos formularios:
 *  - la entrega («¿Quiere factura con cédula?»), porque el comprobante se crea
 *    al entregar y ese es el último momento para pasar de TE a FE sin quemar
 *    un consecutivo;
 *  - la corrección de datos de Hacienda del administrador (InvoiceShow).
 *
 * Con la cédula digitada se consulta Hacienda y se completan nombre, tipo y
 * actividad económica.
 */
trait DatosDeFactura
{
    use ConsultaHacienda;

    public bool $quiereFactura = false;
    public string $facturaNombre = '';
    public string $facturaTipoId = '01';
    public string $facturaId = '';
    public string $facturaEmail = '';
    public string $facturaActividad = '';

    /** Buscador de clientes registrados: elegir uno copia sus datos fiscales. */
    public string $facturaClienteBusqueda = '';
    public $facturaClienteId = null;

    /** Resultados del buscador (mismo criterio que el de la guía). */
    public function getClientesParaFacturaProperty()
    {
        $termino = trim($this->facturaClienteBusqueda);

        if (mb_strlen($termino) < 2) {
            return null;
        }

        return Customer::active()->buscar($termino)->orderBy('name')->limit(15)
            ->get(['id', 'name', 'identification']);
    }

    /**
     * Copia los datos del cliente elegido y deja el buscador listo para otro:
     * lo que se factura es lo que quede en los campos, que se pueden ajustar.
     */
    public function updatedFacturaClienteId($id): void
    {
        $cliente = $id ? Customer::find($id) : null;

        $this->facturaClienteId = null;
        $this->facturaClienteBusqueda = '';

        if (! $cliente) {
            return;
        }

        $this->resetErrorBag(['facturaNombre', 'facturaTipoId', 'facturaId', 'facturaEmail', 'facturaActividad']);
        $this->actividadesHacienda = [];
        $this->avisoHacienda = $cliente->identification ? null
            : "{$cliente->name} no tiene cédula registrada: digitala para poder facturarle.";

        $this->facturaNombre = $cliente->name;
        $this->facturaTipoId = (string) ($cliente->identification_type ?: '01');
        $this->facturaId = (string) $cliente->identification;
        $this->facturaEmail = (string) $cliente->email;
        $this->facturaActividad = (string) $cliente->activity_code;
    }

    /** ¿Tiene sentido preguntar al entregar? Solo si hoy saldría como tiquete. */
    public function puedePedirFactura(?Invoice $guia): bool
    {
        return $guia !== null
            && ! $guia->receptorIdentificado()
            && ! $guia->electronicInvoice()->exists();
    }

    /**
     * Precarga el formulario: lo que la guía ya factura, o los datos del
     * destinatario si va como tiquete (casi siempre es quien retira).
     */
    protected function prepararFactura(?Invoice $guia): void
    {
        $this->resetErrorBag(['facturaNombre', 'facturaTipoId', 'facturaId', 'facturaEmail', 'facturaActividad']);
        $this->actividadesHacienda = [];
        $this->avisoHacienda = null;
        $this->facturaClienteBusqueda = '';
        $this->facturaClienteId = null;

        $actual = $guia?->receptorIdentificado() ? $guia->receptorDeFactura() : null;

        $this->quiereFactura = $actual !== null;
        $this->facturaNombre = (string) ($actual['nombre'] ?? $guia?->recipient_name);
        $this->facturaTipoId = (string) ($actual['tipo'] ?? ($guia?->recipient_identification_type ?: '01'));
        $this->facturaId = (string) ($actual['numero'] ?? $guia?->recipient_identification);
        $this->facturaEmail = (string) ($actual['email'] ?? $guia?->recipient_email);
        $this->facturaActividad = (string) ($guia?->billing_activity_code ?? '');
    }

    /** Al terminar de digitar la cédula se consulta Hacienda. */
    public function updatedFacturaId(): void
    {
        $this->buscarFacturaEnHacienda();
    }

    public function buscarFacturaEnHacienda(): void
    {
        $resultado = $this->consultarContribuyente($this->facturaId);

        if (($resultado['name'] ?? null) === null) {
            return;
        }

        $this->facturaNombre = $resultado['name'];
        $this->facturaTipoId = $resultado['id_type'] ?? $this->facturaTipoId;

        // Se respeta una actividad ya elegida si es de este contribuyente.
        $codigos = array_column($this->actividadesHacienda, 'code');
        if (! in_array($this->facturaActividad, $codigos, true)) {
            $this->facturaActividad = (string) $this->actividadPrincipal();
        }
    }

    /**
     * Los datos validados, o null si no pidió factura.
     *
     * @return array{nombre:string, tipo:string, numero:string, email:?string, actividad:?string}|null
     */
    protected function datosDeFactura(): ?array
    {
        if (! $this->quiereFactura) {
            return null;
        }

        $this->facturaId = preg_replace('/\D/', '', $this->facturaId);
        $this->facturaActividad = Catalogs::normalizeActivityCode($this->facturaActividad);

        $this->validate([
            'facturaNombre'    => 'required|string|max:150',
            'facturaTipoId'    => ['required', Rule::in(array_keys(Customer::IDENTIFICATION_TYPES))],
            'facturaId'        => ['required', 'regex:/^\d{9,12}$/'],
            'facturaEmail'     => 'nullable|email|max:150',
            'facturaActividad' => ['nullable', 'regex:/^(?:\d{6}|\d{4}\.\d)$/'],
        ], [
            'facturaNombre.required' => 'Indicá a nombre de quién va la factura.',
            'facturaId.required'     => 'Para emitir Factura Electrónica hace falta la identificación.',
            'facturaId.regex'        => 'La identificación son de 9 a 12 dígitos, sin guiones ni espacios.',
            'facturaEmail.email'     => 'El correo no es válido.',
            'facturaActividad.regex' => 'El código de actividad son 6 dígitos (ej. 492300) o 4 con decimal (ej. 4923.0).',
        ]);

        return [
            'nombre'    => trim($this->facturaNombre),
            'tipo'      => $this->facturaTipoId,
            'numero'    => $this->facturaId,
            'email'     => trim($this->facturaEmail) ?: null,
            'actividad' => $this->facturaActividad ?: null,
        ];
    }
}
