<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Smoke test: pastikan setiap halaman admin Ticket 06 benar-benar
 * ter-render (bukan 500) dengan data demo.
 */
class AdminUsersSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_all_admin_user_pages_render_with_demo_data(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $admin = User::where('email', 'admin@kasir.local')->firstOrFail();
        $student = User::where('email', 'budi@kantin.sch.id')->firstOrFail();
        $cashier = User::where('email', 'rina@kantin.sch.id')->firstOrFail();

        $admin->assignRfid('0A0B0C0D', 'Kartu Utama');

        $this->actingAs($admin)->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Rina Kasir')
            ->assertSee('0A0B0C0D');

        // Console demo sudah dipakai Rina, jadi halaman create harus
        // menawarkannya lagi hanya setelah ada console bebas.
        $this->actingAs($admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertDontSee('ESP-AABBCCDDEEFF');

        \App\Models\Console::factory()->create([
            'device_id' => 'ESP-112233445566',
            'name' => 'Kasir Cadangan',
            'is_active' => true,
        ]);

        $this->actingAs($admin)->get(route('admin.users.create'))
            ->assertOk()
            ->assertSee('ESP-112233445566');

        $this->actingAs($admin)->get(route('admin.users.show', $student))
            ->assertOk()
            ->assertSee('Budi Santoso')
            ->assertSee('Rp 25.000');

        $this->actingAs($admin)->get(route('admin.users.edit', $student))
            ->assertOk()
            ->assertSee('Kartu RFID')
            ->assertSee('Top-up Saldo')
            ->assertSee('25.000', escape: false);

        $this->actingAs($admin)->get(route('admin.users.edit', $cashier))
            ->assertOk()
            ->assertSee('Device ID Console');

        $this->actingAs($admin)->get(route('admin.users.show', $cashier))
            ->assertOk();
    }

    public function test_edit_page_renders_revoke_action_for_each_active_card(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);

        $admin = User::where('email', 'admin@kasir.local')->firstOrFail();
        $student = User::where('email', 'budi@kantin.sch.id')->firstOrFail();

        // Demo seeder memasang 2 kartu aktif.
        $activeCards = $student->rfidCards->whereNull('revoked_at');
        $this->assertCount(2, $activeCards);

        $response = $this->actingAs($admin)->get(route('admin.users.edit', $student));

        $response->assertOk();

        foreach ($activeCards as $card) {
            $response->assertSee(route('admin.users.rfid.destroy', [$student, $card]), escape: false);
        }
    }

    public function test_dashboard_and_sibling_pages_still_render(): void
    {
        $this->seed(\Database\Seeders\DatabaseSeeder::class);
        $admin = User::where('email', 'admin@kasir.local')->firstOrFail();

        $this->actingAs($admin)->get(route('admin.dashboard'))->assertOk();
        $this->actingAs($admin)->get(route('admin.consoles.index'))->assertOk();
        $this->actingAs($admin)->get(route('admin.menu.index'))->assertOk();
    }

    public function test_login_alias_serves_admin_login_form(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Masuk Admin');
    }
}