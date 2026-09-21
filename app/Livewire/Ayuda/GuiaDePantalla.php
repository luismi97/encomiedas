<?php

namespace App\Livewire\Ayuda;

use App\Support\GuiasDePantalla;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * El recorrido guiado de la pantalla en la que estás.
 *
 * Vive en el layout, así que existe en todas partes; lo que cambia es el
 * contenido, que sale de la ruta actual. Donde no hay guía definida, el
 * componente no pinta nada y no cuesta nada.
 *
 * Se abre solo la primera vez y nunca más: quien ya lo hizo o dijo que no lo
 * quiere no vuelve a toparse con él, ni en esa computadora ni en otra, porque
 * la decisión se guarda en el usuario y no en el navegador.
 */
class GuiaDePantalla extends Component
{
    public bool $abierta = false;
    public int $paso = 0;

    /** La ruta con la que se armó el recorrido. */
    public ?string $clave = null;

    /**
     * La pantalla se la dice el layout; el componente no consulta el request.
     *
     * Además de ser más claro, es lo que lo hace probable: sin esto habría que
     * simular una ruta para comprobar el comportamiento de un recorrido.
     */
    public function mount(?string $clave = null): void
    {
        $this->clave = $clave;

        // Se abre sola solo si hay algo que contar y el usuario no la despachó.
        $this->abierta = $this->guia() !== null
            && auth()->check()
            && ! auth()->user()->yaVioLaGuia($this->clave);
    }

    /** @return array{titulo: string, pasos: array<int, array<string,string>>}|null */
    private function guia(): ?array
    {
        return GuiasDePantalla::para($this->clave);
    }

    public function siguiente(): void
    {
        $total = count($this->guia()['pasos'] ?? []);

        if ($this->paso + 1 >= $total) {
            $this->cerrar('completada');

            return;
        }

        $this->paso++;
    }

    public function anterior(): void
    {
        $this->paso = max(0, $this->paso - 1);
    }

    /**
     * «No me la muestres más».
     *
     * Se guarda igual que terminarla: las dos cosas significan que el usuario ya
     * decidió, y volver a abrirla sería no haberle hecho caso.
     */
    public function descartar(): void
    {
        $this->cerrar('descartada');
    }

    private function cerrar(string $estado): void
    {
        $this->abierta = false;
        $this->paso = 0;

        if (auth()->check() && $this->clave) {
            auth()->user()->marcarGuia($this->clave, $estado);
        }
    }

    /** El botón «Guía» de la barra de arriba, para volver a verla cuando sea. */
    #[On('abrir-guia')]
    public function reabrir(): void
    {
        if ($this->guia() === null) {
            return;
        }

        $this->paso = 0;
        $this->abierta = true;
    }

    public function render()
    {
        $guia = $this->guia();
        $pasos = $guia['pasos'] ?? [];

        return view('livewire.ayuda.guia-de-pantalla', [
            'guia'  => $guia,
            'pasos' => $pasos,
            'actual' => $pasos[$this->paso] ?? null,
            'total' => count($pasos),
            'esElUltimo' => $this->paso + 1 >= count($pasos),
        ]);
    }
}
