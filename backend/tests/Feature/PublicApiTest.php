<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Settings::flush();
        $this->seed(ProductSeeder::class);
    }

    private function payload(array $override = []): array
    {
        $cilukba = Product::where('short_name', 'Cilukba')->first();

        return array_merge([
            'name' => 'Siti Rahma',
            'whatsapp' => '0812-3456-7890',
            'address' => 'Jl. Merpati No. 12 RT 03/RW 05, dekat Indomaret',
            'delivery_date' => now()->addDay()->toDateString(),
            'delivery_time' => '15:00',
            'notes' => 'Sambal dipisah',
            'items' => [['product_id' => $cilukba->id, 'qty' => 2]],
        ], $override);
    }

    public function test_config_returns_public_settings(): void
    {
        $this->getJson('/api/config')
            ->assertOk()
            ->assertJsonPath('data.shipping_fee', 5000)
            ->assertJsonStructure(['data' => ['delivery_slots', 'payment' => ['bank_account'], 'contact' => ['whatsapp']]]);
    }

    public function test_products_list_only_active_with_remaining_quota(): void
    {
        Product::where('short_name', 'Ciweng')->update(['is_active' => false]);

        $res = $this->getJson('/api/products')->assertOk();

        $this->assertCount(2, $res->json('data'));
        $this->assertSame(30, $res->json('data.0.remaining'));
    }

    public function test_customer_can_create_order(): void
    {
        $res = $this->postJson('/api/orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.po_number', 'PO-0001')
            ->assertJsonPath('data.subtotal', 20000)
            ->assertJsonPath('data.shipping_fee', 5000)
            ->assertJsonPath('data.total', 25000)
            ->assertJsonPath('data.status', 'menunggu_pembayaran')
            ->assertJsonPath('data.payment_status', 'belum_bayar')
            ->assertJsonPath('data.whatsapp_masked', '0812-3456-xxxx');

        $order = Order::firstOrFail();
        $this->assertSame('081234567890', $order->whatsapp);
        $this->assertNotEmpty($res->json('data.tracking_token'));
        $this->assertNotNull($order->payment_deadline);
        $this->assertSame(1, $order->items()->count());
    }

    public function test_second_order_gets_next_po_number(): void
    {
        $this->postJson('/api/orders', $this->payload())->assertCreated();
        $this->postJson('/api/orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.po_number', 'PO-0002');
    }

    public function test_order_is_rejected_when_quota_is_exceeded(): void
    {
        $cilukba = Product::where('short_name', 'Cilukba')->first();
        $cilukba->update(['daily_quota' => 3]);

        $this->postJson('/api/orders', $this->payload(['items' => [['product_id' => $cilukba->id, 'qty' => 4]]]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.qty']);

        $this->assertSame(0, Order::count());
    }

    public function test_cancelled_orders_release_quota(): void
    {
        $cilukba = Product::where('short_name', 'Cilukba')->first();
        $cilukba->update(['daily_quota' => 2]);
        $date = now()->addDay()->toDateString();

        $this->postJson('/api/orders', $this->payload())->assertCreated();
        $this->assertSame(0, $cilukba->fresh()->remainingFor($date));

        Order::query()->update(['status' => Order::STATUS_CANCELLED]);
        $this->assertSame(2, $cilukba->fresh()->remainingFor($date));
    }

    public function test_validation_errors(): void
    {
        $this->postJson('/api/orders', $this->payload(['whatsapp' => '12345']))->assertStatus(422)->assertJsonValidationErrors('whatsapp');
        $this->postJson('/api/orders', $this->payload(['delivery_time' => '03:00']))->assertStatus(422)->assertJsonValidationErrors('delivery_time');
        $this->postJson('/api/orders', $this->payload(['delivery_date' => now()->subDay()->toDateString()]))->assertStatus(422)->assertJsonValidationErrors('delivery_date');
        $this->postJson('/api/orders', $this->payload(['items' => []]))->assertStatus(422)->assertJsonValidationErrors('items');
    }

    public function test_past_slot_today_is_rejected_and_server_time_header_present(): void
    {
        $this->travelTo(now()->setTime(11, 0)); // 11:00 WIB: slot 11:00 sudah lewat batas 90 menit
        $res = $this->postJson('/api/orders', $this->payload(['delivery_date' => now()->toDateString(), 'delivery_time' => '11:00']));
        $res->assertStatus(422)->assertJsonValidationErrors('delivery_time');
        $this->assertNotEmpty($res->headers->get('X-Server-Time'));

        $this->travelTo(now()->setTime(8, 0)); // 08:00: slot 15:00 masih bisa
        $this->postJson('/api/orders', $this->payload(['delivery_date' => now()->toDateString(), 'delivery_time' => '15:00']))->assertCreated();
        $this->travelBack();
    }

    public function test_inactive_product_cannot_be_ordered(): void
    {
        $p = Product::where('short_name', 'Cilukba')->first();
        $p->update(['is_active' => false]);

        $this->postJson('/api/orders', $this->payload(['items' => [['product_id' => $p->id, 'qty' => 1]]]))
            ->assertStatus(422);
    }

    public function test_tracking_page_uses_secret_token_and_hides_private_data(): void
    {
        $token = $this->postJson('/api/orders', $this->payload())->json('data.tracking_token');

        $this->getJson("/api/orders/{$token}")
            ->assertOk()
            ->assertJsonPath('data.po_number', 'PO-0001')
            ->assertJsonMissingPath('data.address')
            ->assertJsonMissingPath('data.whatsapp');

        // nomor PO berurutan tidak boleh bisa dipakai untuk mengintip pesanan
        $this->getJson('/api/orders/PO-0001')->assertNotFound();
    }

    public function test_customer_marks_order_as_paid_then_it_waits_for_admin(): void
    {
        $token = $this->postJson('/api/orders', $this->payload())->json('data.tracking_token');

        $this->postJson("/api/orders/{$token}/paid")
            ->assertOk()
            ->assertJsonPath('data.status', 'menunggu_konfirmasi')
            ->assertJsonPath('data.payment_status', 'menunggu');

        // menekan dua kali tidak merusak status
        $this->postJson("/api/orders/{$token}/paid")->assertOk()->assertJsonPath('data.status', 'menunggu_konfirmasi');
    }

    public function test_unpaid_order_expires_and_releases_quota(): void
    {
        $cilukba = Product::where('short_name', 'Cilukba')->first();
        $cilukba->update(['daily_quota' => 2]);
        $date = now()->addDay()->toDateString();

        $token = $this->postJson('/api/orders', $this->payload())->json('data.tracking_token');
        $this->assertSame(0, $cilukba->fresh()->remainingFor($date));

        // lewati batas waktu pembayaran
        Order::query()->update(['payment_deadline' => now()->subMinute()]);

        $this->assertSame(2, $cilukba->fresh()->remainingFor($date));
        $this->getJson("/api/orders/{$token}")->assertOk()->assertJsonPath('data.status', 'dibatalkan');
        $this->postJson("/api/orders/{$token}/paid")->assertStatus(422);
    }

    public function test_mock_gateway_creates_dynamic_qr_and_simulated_payment_marks_paid(): void
    {
        config(['acicu.gateway.driver' => 'mock']);

        $res = $this->postJson('/api/orders', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.payment.driver', 'mock')
            ->assertJsonPath('data.payment.dynamic', true);

        $this->assertStringContainsString('ACICU-MOCK-QRIS', $res->json('data.payment.qr_string'));
        $token = $res->json('data.tracking_token');

        $this->postJson("/api/orders/{$token}/simulate-pay")
            ->assertOk()
            ->assertJsonPath('data.status', 'proses_dapur')
            ->assertJsonPath('data.payment_status', 'lunas');
    }

    public function test_simulated_payment_is_not_available_in_manual_mode(): void
    {
        config(['acicu.gateway.driver' => 'manual']);

        $token = $this->postJson('/api/orders', $this->payload())->assertCreated()
            ->assertJsonPath('data.payment.dynamic', false)->json('data.tracking_token');

        $this->postJson("/api/orders/{$token}/simulate-pay")->assertNotFound();
    }

    public function test_midtrans_webhook_requires_valid_signature(): void
    {
        config(['acicu.gateway.driver' => 'manual']);
        $this->postJson('/api/orders', $this->payload())->assertCreated();
        $order = Order::first();
        $order->update(['payment_ref' => 'ACICU-1-ABCDE']);

        config(['acicu.gateway.driver' => 'midtrans', 'acicu.gateway.midtrans_server_key' => 'SB-key-uji']);

        $payload = [
            'order_id' => 'ACICU-1-ABCDE',
            'status_code' => '200',
            'gross_amount' => $order->total.'.00',
            'transaction_status' => 'settlement',
        ];

        $this->postJson('/api/payments/midtrans/notification', $payload + ['signature_key' => 'palsu'])->assertStatus(403);
        $this->assertSame('belum_bayar', $order->fresh()->payment_status);

        $signature = hash('sha512', $payload['order_id'].$payload['status_code'].$payload['gross_amount'].'SB-key-uji');
        $this->postJson('/api/payments/midtrans/notification', $payload + ['signature_key' => $signature])->assertOk();

        $this->assertSame('lunas', $order->fresh()->payment_status);
        $this->assertSame('proses_dapur', $order->fresh()->status);
    }

    public function test_whatsapp_message_is_sent_through_fonnte_without_blocking_the_order(): void
    {
        config(['acicu.notify.driver' => 'fonnte', 'acicu.notify.fonnte_token' => 'TOKEN-UJI']);
        \Illuminate\Support\Facades\Http::fake(['api.fonnte.com/*' => \Illuminate\Support\Facades\Http::response(['status' => true], 200)]);

        $this->postJson('/api/orders', $this->payload())->assertCreated();

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'fonnte') && $r['target'] === '6281234567890');
    }

    public function test_whatsapp_failure_does_not_break_checkout(): void
    {
        config(['acicu.notify.driver' => 'fonnte', 'acicu.notify.fonnte_token' => 'TOKEN-UJI']);
        \Illuminate\Support\Facades\Http::fake(['api.fonnte.com/*' => \Illuminate\Support\Facades\Http::response('error', 500)]);

        $this->postJson('/api/orders', $this->payload())->assertCreated();
        $this->assertSame(1, Order::count());
    }
}
