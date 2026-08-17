<?php

namespace Tests\Feature\Hacienda;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceTax;
use App\Models\User;

/**
 * Datos mínimos para ejercitar el ciclo de facturación electrónica sin tocar
 * Hacienda: emisor configurado, sucursal, encomienda entregada y comprobante.
 */
trait BuildsHaciendaFixtures
{
    protected function companySettings(array $overrides = []): CompanySetting
    {
        $settings = CompanySetting::instance();

        $settings->fill(array_merge([
            'enabled'               => true,
            'environment'           => 'sandbox',
            'name'                  => 'Encomiendas de Prueba S.A.',
            'commercial_name'       => 'Encomiendas CR',
            'identification_type'   => '02',
            'identification_number' => '3101123456',
            'activity_code'         => '4923.0',
            'province'              => '1',
            'canton'                => '01',
            'district'              => '01',
            'others_signs'          => 'Oficentro, piso 2',
            'phone_code'            => '506',
            'phone'                 => '22001100',
            'email'                 => 'facturacion@encomiendas.test',
            'atv_username'          => 'cpj-3-101-123456@stag.comprobanteselectronicos.go.cr',
            'atv_password'          => 'secreto',
            'certificate_path'      => 'certs/prueba.p12',
            'certificate_pin'       => '1234',
            'default_cabys_code'    => '8511200000000',
        ], $overrides))->save();

        return $settings->fresh();
    }

    protected function branch(): Branch
    {
        return Branch::firstOrCreate(
            ['sucursal_code' => '001', 'terminal_code' => '00001'],
            [
                'name'      => 'San José Central',
                'province'  => '1',
                'canton'    => '01',
                'district'  => '01',
                'is_active' => true,
            ]
        );
    }

    /**
     * Encomienda entregada con dos paquetes e IVA del 13 %.
     * Subtotal 10 000, IVA 1 300, total 11 300.
     */
    protected function deliveredInvoice(Branch $branch, array $overrides = []): Invoice
    {
        $user = User::firstOrCreate(
            ['email' => 'admin@prueba.test'],
            [
                'name'     => 'Admin de Prueba',
                'username' => 'admin_test',
                'password' => bcrypt('secret'),
                'role'     => User::ROLE_ADMIN,
            ]
        );

        $invoice = Invoice::create(array_merge([
            'code'                          => 'ENC-000001',
            'status'                        => Invoice::STATUS_DELIVERED,
            'bill_type'                     => Invoice::BILL_INVOICE,
            'pickup_branch_id'              => $branch->id,
            'delivery_branch_id'            => $branch->id,
            'sender_name'                   => 'Marta Solano',
            'recipient_name'                => 'José Fernández',
            'recipient_identification_type' => '01',
            'recipient_identification'      => '112340567',
            'recipient_email'               => 'jose@cliente.test',
            'subtotal'                      => 10000,
            'discount_amount'               => 0,
            'tax_total'                     => 1300,
            'total'                         => 11300,
            'payment_method'                => 'cash',
            'created_by'                    => $user->id,
            'delivered_at'                  => now(),
        ], $overrides));

        InvoiceItem::create([
            'invoice_id'   => $invoice->id,
            'package_code' => 'PKG-001',
            'description'  => 'Documentos',
            'price'        => 6000,
        ]);

        InvoiceItem::create([
            'invoice_id'   => $invoice->id,
            'package_code' => 'PKG-002',
            'description'  => 'Repuestos',
            'price'        => 4000,
        ]);

        InvoiceTax::create([
            'invoice_id'    => $invoice->id,
            'name'          => 'IVA general',
            'percent'       => 13,
            'hacienda_code' => '08',
            'amount'        => 1300,
        ]);

        return $invoice->fresh(['items', 'taxes', 'pickupBranch']);
    }

    /** Marca un comprobante como aceptado, que es lo que exige una nota. */
    protected function markAccepted(ElectronicInvoice $electronicInvoice, float $subTotal = 10000, float $tax = 1300): ElectronicInvoice
    {
        $electronicInvoice->forceFill([
            'status'      => ElectronicInvoice::STATUS_ACCEPTED,
            'accepted_at' => now(),
            'sub_total'   => $subTotal,
            'total_tax'   => $tax,
            'total'       => $subTotal + $tax,
        ])->save();

        return $electronicInvoice->fresh();
    }
}
