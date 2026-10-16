<?php

namespace Database\Seeders;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Data CONTOH untuk demo: ±130 pesanan 7 hari terakhir + 5 pesanan "hari ini" seperti di desain.
 * Semua nama pelanggan fiktif. Jangan dijalankan di server produksi.
 */
class DemoOrderSeeder extends Seeder
{
    /** @var array<string,array<int,int>> pemakaian kuota [tanggal][product_id] => porsi */
    private array $used = [];

    /** @var array<int,Product> */
    private array $products = [];

    private const NAMES = [
        'Anisa Salsa', 'Rian Pratama', 'Dinda Wulandari', 'Fahmi Nugraha', 'Maya Anggraini', 'Bagas Haryanto',
        'Putri Ayu', 'Rizky Ramadhan', 'Nabila Putri', 'Dimas Yoga', 'Sinta Dewi', 'Agus Salim', 'Lestari Wulan',
        'Hendra Gunawan', 'Yuni Astuti', 'Fajar Pratama', 'Citra Lestari', 'Ilham Maulana', 'Tika Sari', 'Dewi Kartika',
    ];

    private const ADDRESSES = [
        'Jl. Merpati No. 12 RT 03/RW 05, Kel. Sukajadi, dekat Indomaret',
        'Jl. Melati Blok C No. 7, Perumahan Griya Asri, pagar hitam',
        'Jl. Kenanga No. 45 RT 01/RW 02, samping masjid Al-Ikhlas',
        'Jl. Anggrek Raya No. 3, Kel. Tanjung Pinang, rumah cat hijau',
        'Jl. Pelita No. 21, Kos Putri Mawar kamar 4',
        'Jl. Sudirman No. 88, Ruko Lantai 2, kantor PT Maju Jaya',
    ];

    public function run(): void
    {
        if (Order::query()->exists()) {
            $this->command?->warn('Sudah ada pesanan, DemoOrderSeeder dilewati.');

            return;
        }

        $this->products = Product::query()->orderBy('sort_order')->get()->keyBy('short_name')->all();
        if (count($this->products) < 3) {
            $this->command?->warn('Jalankan ProductSeeder dulu.');

            return;
        }

        mt_srand(824);

        $owner = User::query()->where('role', User::ROLE_OWNER)->first();
        $now = now();
        $today = $now->copy()->startOfDay();

        // ---- 1) Pemesan 3 hari terakhir tampil "hari ini" ala desain: 5 pesanan bernomor PO-0824..0828
        $named = [
            ['po' => 824, 'name' => 'Siti Rahma', 'wa' => '081234567890', 'time' => '10:15', 'slot' => '15:30', 'lines' => ['Cilukba' => 2], 'state' => 'pending'],
            ['po' => 825, 'name' => 'Budi Santoso', 'wa' => '081398765432', 'time' => '10:28', 'slot' => '15:30', 'lines' => ['Molachee' => 1], 'state' => 'processing'],
            ['po' => 826, 'name' => 'Anisa Putri', 'wa' => '087711223344', 'time' => '11:04', 'slot' => '15:30', 'lines' => ['Ciweng' => 3], 'state' => 'processing'],
            ['po' => 827, 'name' => 'Dimas Arya', 'wa' => '085655443322', 'time' => '09:10', 'slot' => '11:30', 'lines' => ['Cilukba' => 1], 'state' => 'done'],
            ['po' => 828, 'name' => 'Rina Melati', 'wa' => '082199887766', 'time' => '08:45', 'slot' => '11:30', 'lines' => ['Cilukba' => 4], 'state' => 'done'],
        ];
        foreach ($named as $n) {
            foreach ($n['lines'] as $short => $qty) {
                $this->reserve($today->toDateString(), $this->products[$short]->id, $qty);
            }
        }

        // ---- 2) Pesanan hari-hari sebelumnya (rencana dulu, nomor PO diberikan setelah diurutkan)
        $plan = [];
        foreach ([-6 => 8, -5 => 13, -4 => 18, -3 => 16, -2 => 26, -1 => 23] as $offset => $count) {
            $day = $today->copy()->addDays($offset);
            for ($i = 0; $i < $count; $i++) {
                $created = $day->copy()->setTime(mt_rand(8, 20), mt_rand(0, 59));
                $deliveryDay = ($offset >= -2 && mt_rand(1, 100) <= 25) ? $day->copy()->addDay() : $day->copy();
                $lines = $this->pickItems($deliveryDay->toDateString());
                if ($lines) {
                    $plan[] = ['created' => $created, 'delivery' => $deliveryDay, 'lines' => $lines];
                }
            }
        }
        usort($plan, fn ($a, $b) => $a['created'] <=> $b['created']);

        $number = 824 - count($plan); // supaya pesanan terakhir sebelum hari ini = PO-0823
        foreach ($plan as $p) {
            $past = $p['delivery']->lt($today);
            $cancelled = $past && mt_rand(1, 100) <= 4;
            $this->createOrder([
                'po' => max(1, $number++),
                'created' => $p['created'],
                'delivery' => $p['delivery'],
                'slot' => mt_rand(0, 1) ? '11:30' : '15:30',
                'lines' => $p['lines'],
                'state' => $cancelled ? 'rejected' : ($past ? 'done' : 'processing'),
            ], $owner);
        }

        // ---- 3) Lima pesanan "hari ini" sesuai desain
        foreach ($named as $n) {
            [$h, $m] = array_map('intval', explode(':', $n['time']));
            $created = $today->copy()->setTime($h, $m);
            if ($created->gt($now)) {
                $created = $now->copy()->subMinutes(mt_rand(5, 40))->max($today);
            }

            $lines = [];
            foreach ($n['lines'] as $short => $qty) {
                $lines[] = $this->line($this->products[$short], $qty);
            }

            $this->createOrder([
                'po' => $n['po'],
                'created' => $created,
                'delivery' => $today->copy(),
                'slot' => $n['slot'],
                'lines' => $lines,
                'state' => $n['state'],
                'name' => $n['name'],
                'wa' => $n['wa'],
            ], $owner);
        }

        // ---- 4) Dua pesanan yang menunggu dikonfirmasi (badge "3" di menu bersama PO-0824) + 1 yang belum membayar
        foreach ([829, 830] as $i => $po) {
            $lines = $this->pickItems($today->toDateString()) ?: $this->pickItems($today->copy()->addDay()->toDateString());
            $created = $now->copy()->subMinutes(12 + $i * 18)->max($today);
            $this->createOrder([
                'po' => $po,
                'created' => $created,
                'delivery' => $today->copy(),
                'slot' => '15:30',
                'lines' => $lines,
                'state' => 'pending',
            ], $owner);
        }

        $this->createOrder([
            'po' => 831,
            'created' => $now->copy()->subMinutes(5)->max($today),
            'delivery' => $today->copy()->addDay(),
            'slot' => '11:30',
            'lines' => $this->pickItems($today->copy()->addDay()->toDateString()),
            'state' => 'unpaid',
        ], $owner);

        $this->command?->info('Data contoh dibuat: '.Order::query()->count().' pesanan.');
    }

    /**
     * @param  array{po:int,created:Carbon,delivery:Carbon,slot:string,lines:array,state:string,name?:string,wa?:string}  $d
     */
    private function createOrder(array $d, ?User $owner): void
    {
        $subtotal = array_sum(array_column($d['lines'], 'subtotal'));
        $shipping = (int) \App\Services\Settings::get('shipping_fee');
        $state = $d['state'];

        $paid = in_array($state, ['processing', 'done'], true);
        $order = (new Order)->forceFill([
            'po_number' => 'PO-'.str_pad((string) $d['po'], 4, '0', STR_PAD_LEFT),
            'tracking_token' => Str::random(40),
            'customer_name' => $d['name'] ?? self::NAMES[mt_rand(0, count(self::NAMES) - 1)],
            'whatsapp' => $d['wa'] ?? ('08'.mt_rand(11, 99).mt_rand(10000000, 99999999)),
            'address' => self::ADDRESSES[mt_rand(0, count(self::ADDRESSES) - 1)],
            'delivery_date' => $d['delivery']->toDateString(),
            'delivery_time' => $d['slot'],
            'notes' => mt_rand(1, 100) <= 25 ? 'Sambal dipisah, extra pedas gurih jeruk' : null,
            'subtotal' => $subtotal,
            'shipping_fee' => $shipping,
            'discount' => 0,
            'total' => $subtotal + $shipping,
            'payment_method' => $paid ? (mt_rand(0, 1) ? 'QRIS' : 'Transfer BCA') : null,
            'payment_status' => match (true) {
                $paid => Order::PAY_PAID,
                $state === 'rejected' => Order::PAY_REJECTED,
                $state === 'pending' => Order::PAY_WAITING,
                default => Order::PAY_UNPAID,
            },
            'status' => match ($state) {
                'unpaid' => Order::STATUS_UNPAID,
                'pending' => Order::STATUS_PENDING,
                'processing' => Order::STATUS_PROCESSING,
                'done' => Order::STATUS_DONE,
                default => Order::STATUS_CANCELLED,
            },
            'payment_deadline' => $state === 'unpaid' ? now()->addMinutes(45) : $d['created']->copy()->addMinutes(60),
            'customer_paid_at' => in_array($state, ['pending', 'processing', 'done'], true) ? $d['created']->copy()->addMinutes(mt_rand(2, 12)) : null,
            'rejected_reason' => $state === 'rejected' ? 'Nominal transfer tidak sesuai' : null,
            'paid_at' => $paid ? $d['created']->copy()->addMinutes(mt_rand(5, 40)) : null,
            'validated_by' => ($paid || $state === 'rejected') ? $owner?->id : null,
            'created_at' => $d['created'],
            'updated_at' => $d['created'],
        ]);
        $order->save();
        $order->items()->createMany($d['lines']);
    }

    /** 1-3 menu berbeda, jumlah 1-4 porsi, tidak melebihi kuota harian. */
    private function pickItems(string $date): array
    {
        $roll = mt_rand(1, 100);
        $wanted = $roll <= 55 ? 1 : ($roll <= 85 ? 2 : 3);

        $products = array_values($this->products);
        // acak berurutan dengan mt_rand (hasil sama setiap seeding)
        for ($i = count($products) - 1; $i > 0; $i--) {
            $j = mt_rand(0, $i);
            [$products[$i], $products[$j]] = [$products[$j], $products[$i]];
        }

        $lines = [];
        foreach ($products as $product) {
            if (count($lines) >= $wanted) {
                break;
            }
            $remaining = $product->daily_quota - ($this->used[$date][$product->id] ?? 0);
            if ($remaining <= 0) {
                continue;
            }
            $qty = min($remaining, mt_rand(1, 4));
            $this->reserve($date, $product->id, $qty);
            $lines[] = $this->line($product, $qty);
        }

        return $lines;
    }

    private function line(Product $product, int $qty): array
    {
        return [
            'product_id' => $product->id,
            'product_name' => $product->name,
            'qty' => $qty,
            'unit_price' => $product->price,
            'subtotal' => $product->price * $qty,
            'note' => $product->default_note,
        ];
    }

    private function reserve(string $date, int $productId, int $qty): void
    {
        $this->used[$date][$productId] = ($this->used[$date][$productId] ?? 0) + $qty;
    }
}
