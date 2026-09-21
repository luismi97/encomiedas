<?php

namespace App\Livewire\Customers;

use App\Services\ImportadorDeClientes;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Carga de clientes desde un CSV, en dos tiempos.
 *
 * Primero se analiza y se muestra qué va a pasar con cada fila; solo después,
 * con el resumen a la vista, se escribe. Importar a ciegas dos mil clientes y
 * descubrir el problema al día siguiente no se deshace con un botón.
 */
class CustomerImport extends Component
{
    use WithFileUploads;

    public $archivo = null;

    /** Lo que devolvió el análisis, listo para mostrar y para escribir. */
    public array $filas = [];
    public array $erroresDelArchivo = [];
    public bool $analizado = false;

    /** Qué hacer con los que ya están registrados. */
    public bool $actualizarExistentes = false;

    public ?array $resumen = null;

    public ?string $feedback = null;
    public string $feedbackType = 'success';

    protected function rules(): array
    {
        return [
            // `txt` además de `csv`: según de dónde venga el archivo, el
            // navegador reporta un tipo u otro para exactamente el mismo CSV.
            'archivo' => 'required|file|mimes:csv,txt|max:5120',
        ];
    }

    protected function messages(): array
    {
        return [
            'archivo.required' => 'Elegí el archivo CSV.',
            'archivo.mimes' => 'Tiene que ser un archivo CSV. Si lo tenés en Excel, usá «Guardar como → CSV».',
            'archivo.max' => 'El archivo pasa de 5 MB. Partilo en varios.',
        ];
    }

    private function notify(string $type, string $message): void
    {
        $this->feedbackType = $type;
        $this->feedback = $message;
    }

    public function dismissFeedback(): void
    {
        $this->feedback = null;
    }

    public function updatedArchivo(): void
    {
        $this->reset(['filas', 'erroresDelArchivo', 'analizado', 'resumen']);
        $this->feedback = null;
    }

    public function analizar(ImportadorDeClientes $importador): void
    {
        $this->feedback = null;
        $this->resumen = null;
        $this->validate();

        $resultado = $importador->analizar($this->archivo->getRealPath());

        $this->filas = $resultado['filas'];
        $this->erroresDelArchivo = $resultado['errores'];
        $this->analizado = true;

        if ($resultado['total'] === 0 && $resultado['errores'] === []) {
            $this->notify('error', 'El archivo no trae ninguna fila con datos.');
        }
    }

    public function importar(ImportadorDeClientes $importador): void
    {
        $this->feedback = null;

        if (! $this->analizado) {
            $this->notify('error', 'Primero revisá el archivo.');

            return;
        }

        $this->resumen = $importador->importar($this->filas, $this->actualizarExistentes);

        // El archivo ya se escribió: dejarlo cargado invitaría a importarlo dos
        // veces, y la segunda pasada no se ve distinta de la primera.
        $this->reset(['archivo', 'filas', 'erroresDelArchivo', 'analizado']);

        $this->notify('success', "Listo: {$this->resumen['creados']} cliente(s) creado(s), "
            . "{$this->resumen['actualizados']} actualizado(s), {$this->resumen['omitidos']} sin importar.");
    }

    public function render()
    {
        $importables = collect($this->filas)->where('importable', true);

        return view('livewire.customers.customer-import', [
            'importables'  => $importables->count(),
            'conProblemas' => count($this->filas) - $importables->count(),
            'yaExistentes' => $importables->where('existente', true)->count(),
            'columnas'     => ImportadorDeClientes::COLUMNAS,
        ])->layout('layouts.app', ['title' => 'Importar clientes']);
    }
}
