<?php

namespace Tests\Feature\Hacienda;

use App\Livewire\Hacienda\PendingQueue;
use App\Models\User;
use App\Services\Hacienda\ElectronicBillingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

class PendingQueueLoadingTest extends TestCase
{
    use RefreshDatabase, BuildsHaciendaFixtures;

    /**
     * Cada fila debe llevar su propio wire:target con el id. Si el target se
     * recorta al nombre del metodo, un solo clic pone TODAS las filas en
     * "Procesando" porque Livewire hace match por nombre.
     */
    public function test_cada_fila_tiene_su_propio_wire_target(): void
    {
        $this->companySettings();
        $branch = $this->branch();

        $service = app(ElectronicBillingService::class);
        $ids = [];

        foreach (['ENC-000001', 'ENC-000002', 'ENC-000003'] as $code) {
            $invoice = $this->deliveredInvoice($branch, ['code' => $code]);
            $ids[] = $service->queueForInvoice($invoice)->id;
        }

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        $html = Livewire::actingAs($admin)->test(PendingQueue::class)->html();

        foreach ($ids as $id) {
            $this->assertStringContainsString("wire:target=\"sendOne({$id})\"", $html,
                "Falta el target especifico para el comprobante {$id}");
        }

        // El target generico es justo el que contagiaba a toda la lista.
        $this->assertStringNotContainsString('wire:target="sendOne"', $html);
    }

    /**
     * El mensaje de confirmacion debe salir en la misma respuesta de Livewire.
     * Pintado solo en el layout se quedaba invisible hasta recargar.
     */
    public function test_el_aviso_de_envio_se_ve_en_la_respuesta_de_livewire(): void
    {
        Queue::fake();
        $this->companySettings();
        $branch = $this->branch();

        $invoice = $this->deliveredInvoice($branch, ['code' => 'ENC-000009']);
        $ei = app(ElectronicBillingService::class)->queueForInvoice($invoice);

        $admin = User::where('role', User::ROLE_ADMIN)->firstOrFail();

        Livewire::actingAs($admin)
            ->test(PendingQueue::class)
            ->call('sendOne', $ei->id)
            ->assertSee('cola de envío a Hacienda');
    }
}
