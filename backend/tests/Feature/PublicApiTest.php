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

    private function createOrderToken(): string
    {
        return $this->postJson('/api/orders', $this->payload())->assertCreated()->json('data.tracking_token');
    }

    public function test_paid_claim_with_proof_stores_image_and_moves_to_confirmation(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $token = $this->createOrderToken();

        $res = $this->post("/api/orders/{$token}/paid", [
            'proof' => \Illuminate\Http\UploadedFile::fake()->image('bukti.jpg', 400, 600)->size(300),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'menunggu_konfirmasi');

        $this->assertNotNull($res->json('data.proof_url'));
        $path = Order::first()->proof_path;
        \Illuminate\Support\Facades\Storage::disk('public')->assertExists($path);
    }

    public function test_proof_is_optional_by_default_but_required_when_setting_is_on(): void
    {
        $token = $this->createOrderToken();
        $this->postJson("/api/orders/{$token}/paid")->assertOk()->assertJsonPath('data.status', 'menunggu_konfirmasi');

        \App\Services\Settings::set(['require_proof' => true]);
        \App\Services\Settings::flush();

        $second = $this->postJson('/api/orders', $this->payload(['items' => [['product_id' => Product::first()->id, 'qty' => 1]]]))->assertCreated()->json('data.tracking_token');
        $this->postJson("/api/orders/{$second}/paid")->assertStatus(422)->assertJsonValidationErrors('proof');
    }

    public function test_proof_rejects_non_image_and_oversize_files(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $token = $this->createOrderToken();

        $this->post("/api/orders/{$token}/paid", ['proof' => \Illuminate\Http\UploadedFile::fake()->create('virus.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('proof');

        $this->post("/api/orders/{$token}/paid", ['proof' => \Illuminate\Http\UploadedFile::fake()->image('besar.jpg')->size(3000)], ['Accept' => 'application/json'])
            ->assertStatus(422)->assertJsonValidationErrors('proof');
    }

    public function test_owner_notifier_sends_telegram_message_when_payment_is_claimed(): void
    {
        config(['acicu.owner_notify.driver' => 'telegram', 'acicu.owner_notify.telegram_bot_token' => 'BOT:UJI', 'acicu.owner_notify.telegram_chat_id' => '12345']);
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response(['ok' => true], 200)]);

        $token = $this->createOrderToken();
        $this->postJson("/api/orders/{$token}/paid")->assertOk();

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => str_contains($r->url(), 'api.telegram.org/botBOT:UJI/sendMessage') && $r['chat_id'] === '12345');
    }

    public function test_telegram_failure_does_not_break_the_payment_claim(): void
    {
        config(['acicu.owner_notify.driver' => 'telegram', 'acicu.owner_notify.telegram_bot_token' => 'BOT:UJI', 'acicu.owner_notify.telegram_chat_id' => '12345']);
        \Illuminate\Support\Facades\Http::fake(['api.telegram.org/*' => \Illuminate\Support\Facades\Http::response('error', 500)]);

        $token = $this->createOrderToken();
        $this->postJson("/api/orders/{$token}/paid")->assertOk()->assertJsonPath('data.status', 'menunggu_konfirmasi');
    }
}
