<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\InvoiceExportController;
use App\Http\Controllers\ElectronicInvoiceController;
use App\Livewire\ActivityLogs\ActivityLogIndex;
use App\Livewire\Branches\BranchIndex;
use App\Livewire\Customers\CustomerIndex;
use App\Livewire\Caja\CajaPanel;
use App\Livewire\Dashboard;
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

Route::get('/', fn () => redirect()->route('dashboard'));

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login');
    Route::post('/login', [LoginController::class, 'login']);
});

Route::middleware('auth')->group(function () {
    Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

    Route::get('/dashboard', Dashboard::class)->name('dashboard');

    Route::get('/invoices', InvoiceIndex::class)->name('invoices.index');
    Route::get('/invoices/export/pdf', [InvoiceExportController::class, 'pdf'])->name('invoices.export');
    Route::get('/invoices/{invoice}', InvoiceShow::class)->name('invoices.show');
    Route::get('/invoices/{invoice}/pdf', [InvoiceExportController::class, 'downloadInvoice'])->name('invoices.pdf');
    Route::get('/electronic-invoices/{electronicInvoice}/pdf', [ElectronicInvoiceController::class, 'downloadPdf'])->name('electronic-invoices.pdf');
    Route::get('/electronic-invoices/{electronicInvoice}/respuesta.xml', [ElectronicInvoiceController::class, 'downloadResponseXml'])->name('electronic-invoices.response-xml');

    Route::middleware('role:admin')->group(function () {
        Route::get('/invoices-create', InvoiceForm::class)->name('invoices.create');
        Route::get('/invoices/{invoice}/edit', InvoiceForm::class)->name('invoices.edit');

        Route::get('/caja', CajaPanel::class)->name('caja.index');
        Route::get('/caja/{session}/pdf', [InvoiceExportController::class, 'cashSessionPdf'])->name('caja.pdf');

        Route::get('/dispatches', DispatchIndex::class)->name('dispatches.index');
        Route::get('/dispatches/{dispatch}/pdf', [InvoiceExportController::class, 'dispatchPdf'])->name('dispatches.pdf');

        Route::get('/hacienda/pending', PendingQueue::class)->name('hacienda.pending');

        Route::get('/customers', CustomerIndex::class)->name('customers.index');
        Route::get('/branches', BranchIndex::class)->name('branches.index');
        Route::get('/rates', RateIndex::class)->name('rates.index');
        Route::get('/taxes', TaxIndex::class)->name('taxes.index');
        Route::get('/users', UserIndex::class)->name('users.index');
        Route::get('/activity-logs', ActivityLogIndex::class)->name('activity-logs.index');
        Route::get('/settings/company', CompanySettingsForm::class)->name('settings.company');
    });
});
