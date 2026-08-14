<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\ElectronicInvoice;
use App\Models\Invoice;
use App\Models\Tax;
use App\Models\User;
use App\Services\Hacienda\ClaveGenerator;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Datos de ejemplo (sucursales, repartidores, facturas variadas) para poder
 * presentar una demo con listas, filtros y la cola de Hacienda ya pobladas.
 * Re-ejecutable: php artisan db:seed --class=Database\\Seeders\\DemoDataSeeder
 */
class DemoDataSeeder extends Seeder
{
    private array $senders = [
        ['Marta Solano', '8811-2233'],
        ['Kenneth Araya', '8822-3344'],
        ['Fabiola Chinchilla', '8833-4455'],
        ['Randall Mora', '8844-5566'],
        ['Yolanda Campos', '8855-6677'],
        ['Diego Salazar', '8866-7788'],
        ['Ivannia Rojas', '8877-8899'],
        ['Warner Fallas', '8888-9900'],
    ];

    private array $recipients = [
        ['Maria Gonzalez', '7011-2233'],
        ['Jose Fernandez', '7022-3344'],
        ['Ana Vindas', '7033-4455'],
        ['Carlos Umaña', '7044-5566'],
        ['Laura Mendez', '7055-6677'],
        ['Pablo Castro', '7066-7788'],
        ['Silvia Zamora', '7077-8899'],
        ['Mauricio Brenes', '7088-9900'],
    ];

    private array $descriptions = [
        'Documentos', 'Ropa y calzado', 'Repuestos electrónicos', 'Libros',
        'Alimentos no perecederos', 'Artículos de oficina', 'Juguetes',
        'Piezas de repuesto', 'Cosméticos', 'Accesorios de computo',
    ];

    public function run(): void
    {
        CompanySetting::instance()->fill([
            'name' => 'Encomiendas CR S.A.',
            'commercial_name' => 'Encomiendas CR',
            'identification_type' => '02',
            'identification_number' => '3101123456',
            'activity_code' => '5320.0',
            'province' => '1',
            'canton' => '01',
            'district' => '01',
            'others_signs' => 'Edificio central, San José',
            'phone' => '2222-3333',
            'email' => 'facturacion@encomiendascr.test',
        ])->save();

        $branches = $this->seedBranches();
        $repartidores = $this->seedUsers($branches);
        $tax = Tax::where('is_default', true)->first() ?? Tax::first();

        $admin = User::where('role', User::ROLE_ADMIN)->first();

        // pending, in_transit, delivered, returned, cancelled (proporciones realistas)
        $statusPlan = array_merge(
            array_fill(0, 9, Invoice::STATUS_PENDING),
            array_fill(0, 9, Invoice::STATUS_IN_TRANSIT),
            array_fill(0, 18, Invoice::STATUS_DELIVERED),
            array_fill(0, 5, Invoice::STATUS_RETURNED),
            array_fill(0, 4, Invoice::STATUS_CANCELLED),
        );
        shuffle($statusPlan);

        $deliveredCount = 0;

        foreach ($statusPlan as $i => $finalStatus) {
            $createdAt = Carbon::now()->subDays(random_int(0, 35))->subHours(random_int(0, 23))->subMinutes(random_int(0, 59));
            $pickup = $branches->random();
            $delivery = $branches->where('id', '!=', $pickup->id)->random();
            [$senderName, $senderPhone] = $this->senders[array_rand($this->senders)];
            [$recipientName, $recipientPhone] = $this->recipients[array_rand($this->recipients)];

            $itemCount = random_int(1, 3);
            $items = [];
            $subtotal = 0;
            for ($j = 0; $j < $itemCount; $j++) {
                $price = random_int(3, 18) * 1000;
                $subtotal += $price;
                $items[] = [
                    'package_code' => 'PKG-' . str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT) . '-' . ($j + 1),
                    'size' => ['S', 'M', 'L', 'XL'][array_rand(['S', 'M', 'L', 'XL'])],
                    'weight' => round(random_int(5, 150) / 10, 2),
                    'description' => $this->descriptions[array_rand($this->descriptions)],
                    'price' => $price,
                ];
            }

            $discount = random_int(1, 100) <= 15 ? random_int(1, 3) * 1000 : 0;
            $taxAmount = round(($subtotal - $discount) * (float) $tax->percent / 100, 2);
            $total = $subtotal - $discount + $taxAmount;

            $invoice = new Invoice([
                'status' => Invoice::STATUS_PENDING,
                'pickup_branch_id' => $pickup->id,
                'delivery_branch_id' => $delivery->id,
                'sender_name' => $senderName,
                'sender_phone' => $senderPhone,
                'recipient_name' => $recipientName,
                'recipient_phone' => $recipientPhone,
                'recipient_identification_type' => '01',
                'recipient_identification' => (string) random_int(100000000, 999999999),
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'tax_total' => $taxAmount,
                'total' => $total,
                'created_by' => $admin->id,
                'assigned_to' => random_int(1, 100) <= 85 ? $repartidores->random()->id : null,
            ]);
            $invoice->save();

            foreach ($items as $item) {
                $invoice->items()->create($item);
            }
            $invoice->taxes()->create([
                'tax_id' => $tax->id,
                'name' => $tax->name,
                'percent' => $tax->percent,
                'hacienda_code' => $tax->hacienda_code,
                'amount' => $taxAmount,
            ]);

            // Backdatear created_at para que los filtros de hoy/semana/mes tengan variedad.
            DB::table('invoices')->where('id', $invoice->id)->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

            if ($finalStatus !== Invoice::STATUS_PENDING) {
                $invoice->status = $finalStatus;
                if ($finalStatus === Invoice::STATUS_DELIVERED) {
                    $invoice->delivered_at = (clone $createdAt)->addHours(random_int(2, 48));
                } elseif ($finalStatus === Invoice::STATUS_RETURNED) {
                    $invoice->returned_at = (clone $createdAt)->addHours(random_int(2, 48));
                }
                $invoice->save();
            }

            if ($finalStatus === Invoice::STATUS_DELIVERED) {
                $deliveredCount++;
                $this->maybeCreateElectronicInvoice($invoice, $deliveredCount);
            }
        }
    }

    /** @return \Illuminate\Support\Collection<int,Branch> */
    private function seedBranches()
    {
        $data = [
            ['name' => 'Heredia Centro', 'sucursal_code' => '003', 'province' => '4', 'address' => 'Heredia, Costa Rica'],
            ['name' => 'Cartago Centro', 'sucursal_code' => '004', 'province' => '3', 'address' => 'Cartago, Costa Rica'],
            ['name' => 'Puntarenas Centro', 'sucursal_code' => '005', 'province' => '6', 'address' => 'Puntarenas, Costa Rica'],
            ['name' => 'Limón Centro', 'sucursal_code' => '006', 'province' => '7', 'address' => 'Limón, Costa Rica'],
            ['name' => 'Liberia Centro', 'sucursal_code' => '007', 'province' => '5', 'address' => 'Liberia, Guanacaste'],
        ];

        foreach ($data as $b) {
            Branch::firstOrCreate(
                ['sucursal_code' => $b['sucursal_code'], 'terminal_code' => '00001'],
                ['name' => $b['name'], 'address' => $b['address'], 'province' => $b['province'], 'canton' => '01', 'district' => '01', 'is_active' => true]
            );
        }

        return Branch::where('is_active', true)->get();
    }

    /** @return \Illuminate\Support\Collection<int,User> */
    private function seedUsers($branches)
    {
        $data = [
            ['Luis Rodríguez', 'luis.rodriguez@encomienda.test', 'Alajuela Centro'],
            ['Karla Jiménez', 'karla.jimenez@encomienda.test', 'Heredia Centro'],
            ['Esteban Solís', 'esteban.solis@encomienda.test', 'Cartago Centro'],
            ['Paola Vargas', 'paola.vargas@encomienda.test', 'Puntarenas Centro'],
            ['Andrés Quesada', 'andres.quesada@encomienda.test', 'Limón Centro'],
        ];

        foreach ($data as [$name, $email, $branchName]) {
            $branch = $branches->firstWhere('name', $branchName);
            User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $name,
                    'password' => bcrypt('password'),
                    'role' => User::ROLE_REPARTIDOR,
                    'branch_id' => $branch?->id,
                    'is_active' => true,
                ]
            );
        }

        return User::where('role', User::ROLE_REPARTIDOR)->where('is_active', true)->get();
    }

    /**
     * Crea el comprobante en un estado variado (sin firmar/transmitir de
     * verdad) para que la pantalla de "Pendientes de envío a Hacienda" se
     * vea poblada en la demo, sin necesitar un certificado real.
     */
    private function maybeCreateElectronicInvoice(Invoice $invoice, int $seq): void
    {
        // 10 pendientes, 4 enviados, 3 aceptados, 1 rechazado (de cada 18 entregadas aprox.)
        $status = match (true) {
            $seq % 18 === 0 => ElectronicInvoice::STATUS_REJECTED,
            $seq % 4 === 0 => ElectronicInvoice::STATUS_ACCEPTED,
            $seq % 3 === 0 => ElectronicInvoice::STATUS_SENT,
            default => ElectronicInvoice::STATUS_PENDING,
        };

        $letter = $invoice->receptorIdentificado() ? 'FE' : 'TE';
        $docCode = \App\Services\Hacienda\Catalogs::documentCode($letter);
        $issuedAt = $invoice->delivered_at ?? now();

        $clave = app(ClaveGenerator::class)->generate($invoice->pickupBranch, $docCode, '3101123456', Carbon::parse($issuedAt));

        ElectronicInvoice::create([
            'branch_id' => $invoice->pickup_branch_id,
            'invoice_id' => $invoice->id,
            'document_type' => $docCode,
            'clave' => $clave['clave'],
            'consecutivo' => $clave['consecutivo'],
            'security_code' => $clave['security_code'],
            'environment' => 'sandbox',
            'issued_at' => $issuedAt,
            'currency_code' => 'CRC',
            'exchange_rate' => 1,
            'sub_total' => $invoice->subtotal - $invoice->discount_amount,
            'total_tax' => $invoice->tax_total,
            'total_discount' => $invoice->discount_amount,
            'total' => $invoice->total,
            'status' => $status,
            'hacienda_status' => $status === ElectronicInvoice::STATUS_ACCEPTED ? 'aceptado' : ($status === ElectronicInvoice::STATUS_REJECTED ? 'rechazado' : null),
            'error_message' => $status === ElectronicInvoice::STATUS_REJECTED ? 'Rechazado por Hacienda: -488 desglose de impuestos incompleto (dato de demostración).' : null,
            'accepted_at' => $status === ElectronicInvoice::STATUS_ACCEPTED ? $issuedAt : null,
            'send_attempts' => in_array($status, [ElectronicInvoice::STATUS_SENT, ElectronicInvoice::STATUS_ACCEPTED, ElectronicInvoice::STATUS_REJECTED], true) ? 1 : 0,
        ]);
    }
}
