<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Category;
use App\Models\Console;
use App\Models\MenuItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuditAndSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Setting::flushCache();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);
    }

    // ==================================================================
    // Audit observer
    // ==================================================================

    public function test_creating_console_is_audited(): void
    {
        // Aktor diambil dari user yang sedang login.
        $this->actingAs($this->admin);
        Console::factory()->create(['name' => 'Kasir Baru']);

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'console.created',
            'actor_id' => $this->admin->id,
        ]);
    }

    public function test_system_change_without_login_has_no_actor(): void
    {
        // Perubahan dari seeder/command tidak punya aktor manusia.
        Console::factory()->create();

        $log = AuditLog::where('action', 'console.created')->latest('created_at')->first();

        $this->assertNull($log->actor_id, 'Aksi sistem tidak boleh mengarang aktor');
    }

    public function test_updating_console_logs_old_and_new_values(): void
    {
        $console = Console::factory()->create(['name' => 'Nama Lama', 'is_active' => true]);

        $console->update(['name' => 'Nama Baru']);

        $log = AuditLog::where('action', 'console.updated')->latest('created_at')->first();

        $this->assertNotNull($log);
        $this->assertSame('Nama Lama', $log->metadata['changes']['name']['from']);
        $this->assertSame('Nama Baru', $log->metadata['changes']['name']['to']);
    }

    public function test_console_heartbeat_noise_is_not_audited(): void
    {
        $this->actingAs($this->admin);
        $console = Console::factory()->create();

        // Field yang berubah tiap menit: tidak boleh jadi jejak audit.
        $console->update([
            'last_heartbeat_at' => now(),
            'wifi_rssi' => -55,
            'ip_address' => '192.168.1.7',
        ]);

        $this->assertDatabaseMissing('audit_logs', ['action' => 'console.updated']);
    }

    public function test_console_status_change_is_audited(): void
    {
        $this->actingAs($this->admin);
        $console = Console::factory()->create(['status' => 'offline']);

        $console->update(['status' => 'online']);

        // Perubahan status itu bermakna, jadi tetap dicatat.
        $this->assertDatabaseHas('audit_logs', ['action' => 'console.updated']);
    }

    public function test_deleting_console_is_audited_without_dangling_foreign_key(): void
    {
        $console = Console::factory()->create();

        $console->delete();

        $log = AuditLog::where('action', 'console.deleted')->latest('created_at')->first();

        $this->assertNotNull($log);
        // console_id dibiarkan null karena baris console sudah hilang.
        $this->assertNull($log->console_id);
        $this->assertSame($console->id, $log->metadata['id'], 'Id console tetap tersimpan di metadata');
    }

    public function test_menu_and_category_changes_are_audited(): void
    {
        $category = Category::factory()->create();
        $item = MenuItem::factory()->create(['category_id' => $category->id]);

        $item->update(['price_cents' => 20000]);
        $category->update(['name' => 'Makanan Berat']);
        $item->delete();

        $this->assertDatabaseHas('audit_logs', ['action' => 'menu.created']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'menu.updated']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'menu.deleted']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'category.updated']);
    }

    public function test_password_never_appears_in_audit_log(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $admin->password = 'rahasia123';
        $admin->save();

        $logs = AuditLog::all()->toJson();

        $this->assertStringNotContainsString('rahasia123', $logs);
        $this->assertStringNotContainsString('$2y$', $logs, 'Hash password tidak boleh bocor');
    }

    public function test_audit_log_records_actor_ip_and_metadata(): void
    {
        $this->actingAs($this->admin);
        $console = Console::factory()->create(['name' => 'Kasir Alfa', 'device_id' => 'ESP-AAAABBBBCCCC']);

        $log = AuditLog::where('action', 'console.created')->latest('created_at')->first();

        $this->assertSame($this->admin->id, $log->actor_id);
        $this->assertSame($console->id, $log->console_id);
        $this->assertNotNull($log->ip_address);
        $this->assertSame('Kasir Alfa', $log->metadata['name']);
        $this->assertSame('ESP-AAAABBBBCCCC', $log->metadata['device_id']);
    }

    // ==================================================================
    // Halaman audit log
    // ==================================================================

    public function test_admin_can_view_audit_log_page(): void
    {
        Console::factory()->create();

        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index'))
            ->assertOk()
            ->assertSee('Jejak Audit')
            ->assertSee('console.created');
    }

    public function test_guest_cannot_view_audit_logs(): void
    {
        $this->get(route('admin.audit-logs.index'))->assertRedirect(route('login'));
    }

    public function test_cashier_cannot_view_audit_logs(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'cashier']))
            ->get(route('admin.audit-logs.index'))
            ->assertForbidden();
    }

    public function test_audit_log_can_be_filtered_by_action(): void
    {
        Console::factory()->create(['device_id' => 'ESP-FILTER123']);
        $category = Category::factory()->create();
        $category->update(['name' => 'Kategori Berubah']);

        $response = $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', ['action' => 'category.updated']))
            ->assertOk()
            ->assertSee('category.updated');

        // Baris console harus terfilter keluar dari tabel.
        $response->assertDontSee('ESP-FILTER123');
    }

    public function test_audit_log_respects_date_range(): void
    {
        Console::factory()->create();

        // Rentang masa depan tidak boleh memuat apa pun.
        $this->actingAs($this->admin)
            ->get(route('admin.audit-logs.index', [
                'start' => now()->addYear()->toDateString(),
                'end' => now()->addYear()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSee('Belum ada jejak audit');
    }

    public function test_audit_log_csv_export(): void
    {
        Console::factory()->create(['name' => 'Console Ekspor']);

        $response = $this->actingAs($this->admin)->get(route('admin.audit-logs.export'));

        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Aksi', $csv);
        $this->assertStringContainsString('console.created', $csv);
        $this->assertStringContainsString('Console Ekspor', $csv);
    }

    // ==================================================================
    // Settings
    // ==================================================================

    public function test_admin_can_view_settings_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Pengaturan')
            ->assertSee('order_rfid_timeout_seconds');
    }

    public function test_admin_can_update_settings(): void
    {
        $response = $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.update'), [
                'app_timezone' => 'Asia/Makassar',
                'app_receipt_prefix' => 'KTR',
                'order_rfid_timeout_seconds' => 90,
                'order_auto_void_minutes' => 3,
                'printer_show_price' => '1',
            ]);

        $response->assertSessionHasNoErrors();
        $response->assertRedirect(route('admin.settings.index'));
        $response->assertSessionHas('success');

        $this->assertSame('Asia/Makassar', Setting::get('app_timezone'));
        $this->assertSame(90, Setting::get('order_rfid_timeout_seconds'));
        $this->assertSame(3, Setting::get('order_auto_void_minutes'));
        $this->assertTrue(Setting::get('printer_show_price'));

        // Halaman settings harus menampilkan nilai baru.
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertSee('value="90"', escape: false);
    }

    public function test_settings_update_is_audited(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), ['order_rfid_timeout_seconds' => 120]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACT_ADMIN_SETTINGS_UPDATED,
            'actor_id' => $this->admin->id,
        ]);

        $log = AuditLog::where('action', AuditLogger::ACT_ADMIN_SETTINGS_UPDATED)->latest('created_at')->first();

        $this->assertSame(60, $log->metadata['changed']['order_rfid_timeout_seconds']['from']);
        $this->assertSame(120, $log->metadata['changed']['order_rfid_timeout_seconds']['to']);
    }

    public function test_settings_without_changes_says_so(): void
    {
        Setting::put('order_rfid_timeout_seconds', 60, 'int');

        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), ['order_rfid_timeout_seconds' => 60])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success');
    }

    public function test_settings_validation_rejects_out_of_range(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.update'), ['order_rfid_timeout_seconds' => 5])
            ->assertSessionHasErrors('order_rfid_timeout_seconds');

        $this->assertSame(60, Setting::get('order_rfid_timeout_seconds', 60), 'Nilai lama tidak boleh berubah');
    }

    public function test_rfid_timeout_setting_affects_new_orders(): void
    {
        Setting::put('order_rfid_timeout_seconds', 120, 'int');

        $this->assertSame(120, \App\Models\Transaction::rfidTimeoutSeconds());

        Setting::put('order_rfid_timeout_seconds', 60, 'int');
        $this->assertSame(60, \App\Models\Transaction::rfidTimeoutSeconds());
    }

    public function test_setting_defaults_when_not_seeded(): void
    {
        Setting::flushCache();

        $this->assertSame(60, Setting::get('order_rfid_timeout_seconds', 60));
        $this->assertNull(Setting::get('tidak.ada.key', null));
    }

    public function test_setting_boolean_and_int_types(): void
    {
        Setting::put('flag.on', true, 'bool');
        Setting::put('flag.off', false, 'bool');
        Setting::put('angka', '42', 'int');

        $this->assertTrue(Setting::get('flag.on'));
        $this->assertFalse(Setting::get('flag.off'));
        $this->assertSame(42, Setting::get('angka'));
    }

    public function test_settings_save_survives_a_full_browser_round_trip(): void
    {
        // Meniru alur browser: buka form -> spoof PUT -> ikut redirect -> cek hasil.
        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.update'), ['_method' => 'PUT', 'order_rfid_timeout_seconds' => 150])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Pengaturan berhasil disimpan.')
            ->assertSee('value="150"', escape: false);
    }

public function test_wrong_current_password_round_trip_shows_error(): void
    {
        $this->actingAs($this->admin)->get(route('admin.settings.index'))->assertOk();

        $this->actingAs($this->admin)
            ->post(route('admin.settings.password'), [
                '_method' => 'PUT',
                'current_password' => 'salah',
                'password' => 'katasandi123',
                'password_confirmation' => 'katasandi123',
            ])
            ->assertRedirect(route('admin.settings.index'))
            ->assertSessionHasErrors('current_password');

        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Password saat ini salah.');

        $this->assertTrue(Hash::check('password123', $this->admin->fresh()->password));
    }

public function test_settings_forms_spoof_put_method(): void
    {
        // Route-nya PUT, jadi form HTML wajib menyamar sebagai PUT lewat @method.
        // Tanpa ini, tombol "Simpan" di browser membalas 405.
        $html = $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(
            2,
            substr_count($html, 'name="_method" value="PUT"'),
            'Kedua form (simpan pengaturan & ganti password) harus spoof PUT'
        );
    }

    public function test_flash_success_message_renders_on_admin_layout(): void
    {
        // Flash message harus tampil di layout bersama, bukan tiap halaman
        // menulis sendiri-sendiri (dulu hanya consoles/edit yang punya).
        $html = $this->actingAs($this->admin)
            ->withSession(['success' => 'Pengaturan berhasil disimpan.'])
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Pengaturan berhasil disimpan.', $html);
    }

    public function test_validation_errors_render_on_admin_layout(): void
    {
        // PUT gagal -> flash error, lalu halaman tujuan harus menampilkannya.
        $this->actingAs($this->admin)
            ->from(route('admin.settings.index'))
            ->put(route('admin.settings.password'), [
                'current_password' => 'salah',
                'password' => 'sekali-saja-123',
                'password_confirmation' => 'sekali-saja-123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->actingAs($this->admin)
            ->get(route('admin.settings.index'))
            ->assertOk()
            ->assertSee('Password saat ini salah.');
    }

    // ==================================================================
    // Ganti password admin
    // ==================================================================

    public function test_admin_can_change_password_with_correct_current_password(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password'), [
                'current_password' => 'password123',
                'password' => 'barubaru123',
                'password_confirmation' => 'barubaru123',
            ])
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('barubaru123', $this->admin->fresh()->password));
    }

    public function test_password_change_rejects_wrong_current_password(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password'), [
                'current_password' => 'salah-total',
                'password' => 'barubaru123',
                'password_confirmation' => 'barubaru123',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('password123', $this->admin->fresh()->password));
    }

    public function test_password_change_rejects_same_password(): void
    {
        $this->actingAs($this->admin)
            ->put(route('admin.settings.password'), [
                'current_password' => 'password123',
                'password' => 'password123',
                'password_confirmation' => 'password123',
            ])
            ->assertSessionHasErrors('password');
    }

    public function test_password_change_is_audited_without_leaking_password(): void
    {
        $this->actingAs($this->admin)->put(route('admin.settings.password'), [
            'current_password' => 'password123',
            'password' => 'barubaru123',
            'password_confirmation' => 'barubaru123',
        ]);

        $this->assertDatabaseHas('audit_logs', ['action' => AuditLogger::ACT_ADMIN_PASSWORD_CHANGED]);
        $this->assertStringNotContainsString('barubaru123', AuditLog::all()->toJson());
    }
}