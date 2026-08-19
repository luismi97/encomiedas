<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\InvoiceExportController;
use App\Http\Controllers\RastreoController;
use App\Http\Controllers\ElectronicInvoiceController;
use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Branches\BranchIndex;
use App\Livewire\CashRegisters\CashRegisterIndex;
use App\Livewire\PackageTypes\PackageTypeIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Caja\CajaPanel;
use App\Livewire\Chofer\ChoferPanel;
use App\Livewire\Credito\CreditoPanel;
use App\Livewire\Dashboard;
use App\Livewire\Reportes\ReportePanel;
use App\Livewire\Dispatches\DispatchIndex;
use App\Livewire\Hacienda\PendingQueue;
use App\Livewire\Invoices\InvoiceForm;
use App\Livewire\Invoices\InvoiceIndex;
use App\Livewire\Invoices\InvoiceShow;
use App\Livewire\Rates\RateIndex;
use App\Livewire\Settings\CompanySettingsForm;
use App\Livewire\Taxes\TaxIndex;
use App\Livewire\Users\UserIndex;
use Illuminate\Support\Facades\Route;

// Las rutas de mantenimiento (/__deploy/*) viven en routes/deploy.php, fuera
// del grupo `web`: necesitan responder aunque la base todavía no exista.

/*
 | Seguimiento público: sin login, se llega escaneando el QR del recibo.
 |
 | El límite de intentos frena el recorrido de consecutivos a fuerza bruta, pero
 | la protección de fondo es que la página no muestra datos personales ni
 | montos: aunque alguien enumere, no obtiene nada aprovechable.
 */
Route::middleware('throttle:20,1')->group(function () {
    Route::get('/rastreo', [RastreoController::class, 'buscar'])->name('rastreo.buscar');
    Route::get('/rastreo/{code}', [RastreoController::class, 'ver'])->name('rastreo.ver');
});

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');

    // Recuperación de contraseña. Con límite de intentos: el formulario dice lo
    // mismo exista o no la cuenta, pero sin tope se podría medir el tiempo de
    // respuesta para deducir qué correos están registrados.
    Route::middleware('throttle:6,1')->group(function () {
        Route::get('/olvide-contrasena', [PasswordResetController::class, 'solicitar'])->name('password.request');
        Route::post('/olvide-contrasena', [PasswordResetController::class, 'enviarEnlace'])->name('password.email');
        Route::get('/restablecer/{token}', [PasswordResetController::class, 'formulario'])->name('password.reset');
        Route::post('/restablecer', [PasswordResetController::class, 'actualizar'])->name('password.update');
    });
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/invoices', InvoiceIndex::class)->name('invoices.index');
    Route::get('/invoices/export/pdf', [InvoiceExportController::class, 'pdf'])->name('invoices.export');
    Route::get('/invoices/{invoice}', InvoiceShow::class)->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [InvoiceExportController::class, 'downloadInvoice'])->name('invoices.pdf');
    Route::get('/invoices/{invoice}/recibo', [InvoiceExportController::class, 'reciboTermico'])->name('invoices.recibo');
    Route::get('/invoices/{invoice}/etiqueta', [InvoiceExportController::class, 'etiquetaPaquete'])->name('invoices.etiqueta');
    // Vista de calle: el chofer solo ve el cierre que trae asignado.
    Route::middleware('role:admin,repartidor')->group(function () {
        Route::get('/mi-ruta', ChoferPanel::class)->name('chofer.index');
    });

    Route::get('/electronic-invoices/{electronicInvoice}/pdf', [ElectronicInvoiceController::class, 'downloadPdf'])->name('electronic-invoices.pdf');
    Route::get('/electronic-invoices/{electronicInvoice}/respuesta.xml', [ElectronicInvoiceController::class, 'downloadResponseXml'])->name('electronic-invoices.response-xml');

    /*
     | Operación diaria: recibir encomiendas, cobrar, imprimir, despachar y
     | recibir cierres. El cajero hace todo esto en SU sede; el administrador,
     | en todas.
     */
    Route::middleware('role:admin,cajero')->group(function () {
        Route::get('/invoices-create', InvoiceForm::class)->name('invoices.create');
        Route::get('/invoices/{invoice}/edit', InvoiceForm::class)->name('invoices.edit');

        Route::get('/caja', CajaPanel::class)->name('caja.index');
        Route::get('/caja/{session}/pdf', [InvoiceExportController::class, 'cashSessionPdf'])->name('caja.pdf');

        Route::get('/dispatches', DispatchIndex::class)->name('dispatches.index');
        Route::get('/dispatches/{dispatch}/pdf', [InvoiceExportController::class, 'dispatchPdf'])->name('dispatches.pdf');

        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/credito', CreditoPanel::class)->name('credito.index');
        Route::get('/reportes', ReportePanel::class)->name('reportes.index');
        Route::get('/credito/{statement}/pdf', [InvoiceExportController::class, 'creditStatementPdf'])->name('credito.pdf');
    });

    /*
     | Configuración del sistema y lo fiscal: solo administración.
     */
    Route::middleware('role:admin')->group(function () {
        Route::get('/hacienda/pending', PendingQueue::class)->name('hacienda.pending');

        Route::get('/branches', BranchIndex::class)->name('branches.index');
        Route::get('/cash-registers', CashRegisterIndex::class)->name('cash-registers.index');
        Route::get('/package-types', PackageTypeIndex::class)->name('package-types.index');
        Route::get('/rates', RateIndex::class)->name('rates.index');
        Route::get('/taxes', TaxIndex::class)->name('taxes.index');
        Route::get('/users', UserIndex::class)->name('users.index');
        Route::get('/activity-logs', ActivityLogIndex::class)->name('activity-logs.index');
        Route::get('/settings/company', CompanySettingsForm::class)->name('settings.company');
    });
});
