<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\ProductSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AdminApiTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $staff;

    protected function setUp(): void
    {
        parent::setUp();
        \App\Services\Settings::flush();
        $this->seed(ProductSeeder::class);

        $this->owner = User::create(['name' => 'Owner', 'username' => 'owner', 'email' => 'owner@acicu.test', 'role' => 'owner', 'password' => 'rahasia-123']);
        $this->staff = User::create(['name' => 'Staf', 'username' => 'staf', 'email' => 'staf@acicu.test', 'role' => 'admin', 'password' => 'rahasia-123']);
    }

    private function makeOrder(array $attrs = []): Order
    {
        $p = Product::where('short_name', 'Cilukba')->first();
        $order = Order::create($attrs + [
            'po_number' => Order::nextPoNumber(),
            'tracking_token' => bin2hex(random_bytes(16)),
            'customer_name' => 'Budi Santoso',
            'whatsapp' => '081398765432',
            'address' => 'Jl. Kenanga No. 45',
            'delivery_date' => now()->toDateString(),
            'delivery_time' => '15:00',
            'subtotal' => 20000, 'shipping_fee' => 5000, 'discount' => 0, 'total' => 25000,
            'payment_status' => 'menunggu',
            'status' => 'menunggu_konfirmasi',
        ]);
        $order->items()->create(['product_id' => $p->id, 'product_name' => $p->name, 'qty' => 2, 'unit_price' => 10000, 'subtotal' => 20000]);

        return $order;
    }

    public function test_login_with_email_or_username_returns_token(): void
    {
        $this->postJson('/api/admin/login', ['login' => 'owner@acicu.test', 'password' => 'rahasia-123'])
            ->assertOk()->assertJsonStructure(['data' => ['token', 'user' => ['role', 'role_label']]]);

        $this->postJson('/api/admin/login', ['login' => 'owner', 'password' => 'rahasia-123'])
            ->assertOk()->assertJsonPath('data.user.role_label', 'Super Admin');
    }

    public function test_login_fails_with_wrong_password_or_inactive_account(): void
    {
        $this->postJson('/api/admin/login', ['login' => 'owner', 'password' => 'salah'])->assertStatus(401);

        $this->staff->update(['is_active' => false]);
        $this->postJson('/api/admin/login', ['login' => 'staf', 'password' => 'rahasia-123'])->assertStatus(401);
    }

    public function test_admin_endpoints_require_token(): void
    {
        $this->getJson('/api/admin/dashboard')->assertUnauthorized();
        $this->getJson('/api/admin/orders')->assertUnauthorized();
        $this->getJson('/api/admin/products')->assertUnauthorized();
    }

    public function test_token_from_login_works_and_logout_revokes_it(): void
    {
        $token = $this->postJson('/api/admin/login', ['login' => 'owner', 'password' => 'rahasia-123'])->json('data.token');

        $this->withHeader('Authorization', "Bearer {$token}")->getJson('/api/admin/me')->assertOk()->assertJsonPath('data.username', 'owner');
        $this->withHeader('Authorization', "Bearer {$token}")->postJson('/api/admin/logout')->assertOk();

        $this->assertSame(0, $this->owner->tokens()->count());
    }

    public function test_reports_are_owner_only(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/reports')->assertForbidden();
        $this->get('/api/admin/reports/export')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/admin/reports')->assertOk()->assertJsonPath('data.summary.orders', 0);
    }

    public function test_unpaid_order_can_also_be_confirmed_by_admin(): void
    {
        $order = $this->makeOrder(['status' => 'menunggu_pembayaran', 'payment_status' => 'belum_bayar']);
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/admin/orders/{$order->id}/validate")->assertOk()->assertJsonPath('data.status', 'proses_dapur');
    }

    public function test_validating_payment_marks_paid_and_starts_kitchen_process(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/admin/orders/{$order->id}/validate", ['payment_method' => 'QRIS'])
            ->assertOk()
            ->assertJsonPath('data.payment_status', 'lunas')
            ->assertJsonPath('data.status', 'proses_dapur');

        $this->assertNotNull($order->fresh()->paid_at);
        $this->assertSame($this->staff->id, $order->fresh()->validated_by);
        $this->assertDatabaseHas('activity_logs', ['action' => 'order.validate', 'user_id' => $this->staff->id]);

        // tidak bisa divalidasi dua kali
        $this->postJson("/api/admin/orders/{$order->id}/validate")->assertStatus(422);
    }

    public function test_rejecting_payment_cancels_order(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->staff);

        $this->postJson("/api/admin/orders/{$order->id}/reject", ['reason' => 'Nominal kurang'])
            ->assertOk()->assertJsonPath('data.status', 'dibatalkan');
    }

    public function test_status_cannot_advance_before_payment_is_validated(): void
    {
        $order = $this->makeOrder();
        Sanctum::actingAs($this->staff);

        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'selesai'])->assertStatus(422);

        $this->postJson("/api/admin/orders/{$order->id}/validate")->assertOk();
        $this->patchJson("/api/admin/orders/{$order->id}/status", ['status' => 'selesai'])->assertOk()->assertJsonPath('data.status', 'selesai');
    }

    public function test_orders_list_supports_search_status_and_date_filters(): void
    {
        $this->makeOrder();
        $this->makeOrder(['customer_name' => 'Rian Pratama', 'status' => 'selesai', 'payment_status' => 'lunas']);

        $this->getJson('/api/admin/orders')->assertUnauthorized();

        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/orders?search=Rian')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?status=selesai')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/admin/orders?date='.now()->addDays(3)->toDateString())->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_dashboard_returns_cards_and_seven_day_trend(): void
    {
        $this->makeOrder();
        Sanctum::actingAs($this->staff);

        $res = $this->getJson('/api/admin/dashboard')->assertOk();

        $this->assertCount(7, $res->json('data.trend'));
        $this->assertSame(1, $res->json('data.cards.orders_today.count'));
        $this->assertSame(1, $res->json('data.cards.to_process.count'));
    }

    public function test_orders_export_returns_xlsx_file(): void
    {
        $this->makeOrder();
        Sanctum::actingAs($this->staff);

        $res = $this->get('/api/admin/orders/export')->assertOk();
        $this->assertStringContainsString('spreadsheetml.sheet', $res->headers->get('Content-Type'));
        $this->assertStringStartsWith('PK', file_get_contents($res->baseResponse->getFile()->getPathname()));
    }

    public function test_report_only_counts_paid_orders_and_masks_phone(): void
    {
        $this->makeOrder(); // belum lunas -> tidak dihitung
        $this->makeOrder(['payment_status' => 'lunas', 'status' => 'selesai', 'payment_method' => 'QRIS']);

        Sanctum::actingAs($this->owner);
        $res = $this->getJson('/api/admin/reports')->assertOk();

        $this->assertSame(1, $res->json('data.summary.orders'));
        $this->assertSame(2, $res->json('data.summary.portions'));
        $this->assertSame(20000, $res->json('data.summary.revenue'));
        $this->assertSame('0813-9876-xxxx', $res->json('data.transactions.0.whatsapp_masked'));
    }

    public function test_product_crud_with_image_upload(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->staff);

        $created = $this->post('/api/admin/products', [
            'name' => 'Cimol Bojot', 'category' => 'Aci Kering', 'price' => 12000, 'daily_quota' => 20,
            'is_active' => 1, 'image' => UploadedFile::fake()->image('cimol.jpg'),
        ], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.status', 'tersedia');

        $id = $created->json('data.id');
        $this->assertNotNull(Product::find($id)->image_path);

        $this->putJson("/api/admin/products/{$id}", [
            'name' => 'Cimol Bojot', 'category' => 'Aci Kering', 'price' => 13000, 'daily_quota' => 0, 'is_active' => true,
        ])->assertOk()->assertJsonPath('data.status', 'habis');

        $this->deleteJson("/api/admin/products/{$id}")->assertOk();
        $this->assertNull(Product::find($id));
    }
    public function test_settings_owner_only_and_slots_validated(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/settings')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/admin/settings')->assertOk()->assertJsonPath('data.delivery_slots.0', '06:00')
            ->assertJsonPath('data.delivery_slots.16', '22:00');

        $this->putJson('/api/admin/settings', ['delivery_slots' => ['25:00']])->assertStatus(422);

        $this->putJson('/api/admin/settings', [
            'delivery_slots' => ['10:00', '08:00'], 'shipping_fee' => 7000, 'whatsapp' => '0812-3456-7000', 'store_open' => false,
        ])->assertOk()->assertJsonPath('data.delivery_slots', ['08:00', '10:00'])->assertJsonPath('data.whatsapp', '628123457000');

        // perubahan langsung tampil di API publik dan memblokir pesanan saat toko ditutup
        $this->getJson('/api/config')->assertJsonPath('data.shipping_fee', 7000)->assertJsonPath('data.store_open', false);
        $this->postJson('/api/orders', ['name' => 'Tes'])->assertStatus(422)->assertJsonPath('message', 'Pre-order sedang ditutup. Silakan kembali lagi nanti.');
    }

    public function test_quick_stock_update_reflects_on_public_menu(): void
    {
        Sanctum::actingAs($this->staff);
        $p = Product::where('short_name', 'Cilukba')->first();

        $this->patchJson("/api/admin/products/{$p->id}/stock", ['daily_quota' => 5])->assertOk()->assertJsonPath('data.daily_quota', 5);
        $this->patchJson("/api/admin/products/{$p->id}/stock", ['is_active' => false])->assertOk()->assertJsonPath('data.status', 'nonaktif');

        $ids = collect($this->getJson('/api/products')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($p->id));
    }

    public function test_user_management_rules(): void
    {
        Sanctum::actingAs($this->staff);
        $this->getJson('/api/admin/users')->assertForbidden();

        Sanctum::actingAs($this->owner);
        $new = $this->postJson('/api/admin/users', [
            'name' => 'Admin Baru', 'username' => 'adminbaru', 'email' => 'baru@acicu.test', 'role' => 'admin', 'password' => 'SandiKuat12345',
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/admin/users', ['name' => 'X', 'username' => 'x1', 'email' => 'x@acicu.test', 'role' => 'admin', 'password' => 'lemah'])->assertStatus(422);

        // tidak boleh menonaktifkan diri sendiri / owner terakhir
        $this->patchJson("/api/admin/users/{$this->owner->id}", ['is_active' => false])->assertStatus(422);

        $this->patchJson("/api/admin/users/{$new}", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->postJson('/api/admin/login', ['login' => 'adminbaru', 'password' => 'SandiKuat12345'])->assertStatus(401);

        $this->postJson("/api/admin/users/{$new}/password", ['password' => 'SandiBaru123456'])->assertOk();
    }

    public function test_change_own_password(): void
    {
        Sanctum::actingAs($this->staff);

        $this->postJson('/api/admin/me/password', ['current_password' => 'salah', 'password' => 'SandiBaru12345', 'password_confirmation' => 'SandiBaru12345'])->assertStatus(422);

        $this->postJson('/api/admin/me/password', ['current_password' => 'rahasia-123', 'password' => 'SandiBaru12345', 'password_confirmation' => 'SandiBaru12345'])->assertOk();
        $this->postJson('/api/admin/login', ['login' => 'staf', 'password' => 'SandiBaru12345'])->assertOk();
    }

    public function test_whatsapp_test_endpoint_is_owner_only_and_log_mode_does_not_send(): void
    {
        Sanctum::actingAs($this->staff);
        $this->postJson('/api/admin/settings/test-whatsapp', ['phone' => '081234567890'])->assertForbidden();

        Sanctum::actingAs($this->owner);
        config(['acicu.notify.driver' => 'log']);
        $this->postJson('/api/admin/settings/test-whatsapp', ['phone' => '081234567890'])
            ->assertOk()->assertJsonPath('data.driver', 'log');
        $this->postJson('/api/admin/settings/test-whatsapp', ['phone' => 'abc'])->assertStatus(422);
    }
}
