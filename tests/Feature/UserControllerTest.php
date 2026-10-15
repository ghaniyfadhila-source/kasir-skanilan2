<?php

namespace Tests\Feature;

use App\Models\Console;
use App\Models\RfidCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);
    }

    private function makeConsole(array $attrs = []): Console
    {
        return Console::factory()->create(array_merge([
            'device_id' => 'ESP-AAAABBBBCCCC',
            'is_active' => true,
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // Akses
    // ------------------------------------------------------------------

    public function test_admin_can_access_users_index(): void
    {
        User::factory()->count(3)->create(['role' => 'student']);

        $response = $this->actingAs($this->admin)->get(route('admin.users.index'));

        $response->assertStatus(200);
        $response->assertSee('Kelola User');
    }

    public function test_guest_cannot_access_users_index(): void
    {
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_cashier_cannot_access_users_index(): void
    {
        $cashier = User::factory()->create(['role' => 'cashier']);

        $this->actingAs($cashier)->get(route('admin.users.index'))->assertForbidden();
    }

    public function test_admin_can_open_create_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.users.create'))
            ->assertStatus(200)
            ->assertSee('Tambah User Baru');
    }

    // ------------------------------------------------------------------
    // Pencarian & filter
    // ------------------------------------------------------------------

    public function test_index_can_search_by_name_and_email(): void
    {
        User::factory()->create(['name' => 'Budi Santoso', 'role' => 'student']);
        User::factory()->create(['name' => 'Siti Aminah', 'email' => 'siti@kantin.sch.id', 'role' => 'student']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => 'Budi']))
            ->assertSee('Budi Santoso')
            ->assertDontSee('Siti Aminah');

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['search' => 'siti@kantin.sch.id']))
            ->assertSee('Siti Aminah');
    }

    public function test_index_can_filter_by_role(): void
    {
        $console = $this->makeConsole();
        User::factory()->create(['name' => 'Murid Satu', 'role' => 'student']);
        User::factory()->create([
            'name' => 'Kasir Satu',
            'role' => 'cashier',
            'device_id' => $console->device_id,
        ]);

        $this->actingAs($this->admin)
            ->get(route('admin.users.index', ['role' => 'cashier']))
            ->assertSee('Kasir Satu')
            ->assertDontSee('Murid Satu');
    }

    // ------------------------------------------------------------------
    // Create — Siswa
    // ------------------------------------------------------------------

    public function test_admin_can_create_student_with_initial_balance(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Andi Wijaya',
            'email' => 'andi@kantin.sch.id',
            'role' => 'student',
            'initial_balance' => 25000,
        ]);

        $user = User::where('email', 'andi@kantin.sch.id')->first();

        $this->assertNotNull($user);
        $response->assertRedirect(route('admin.users.edit', $user));

        // Rupiah harus dikonversi ke sen.
        $this->assertSame(25000 * 100, $user->balance_cents);
        $this->assertNull($user->device_id);
    }

    public function test_student_password_is_generated_when_left_blank(): void
    {
        $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Tanpa Password',
            'email' => 'nopass@kantin.sch.id',
            'role' => 'student',
        ]);

        $user = User::where('email', 'nopass@kantin.sch.id')->first();

        $this->assertNotNull($user);
        // Cast `hashed` di model harus menghasilkan hash yang bisa diverifikasi.
        $this->assertNotSame('', $user->password);
        $this->assertTrue(password_verify('', $user->password) === false);
    }

    public function test_email_must_be_unique(): void
    {
        User::factory()->create(['email' => 'kembar@kantin.sch.id', 'role' => 'student']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Duplikat',
                'email' => 'kembar@kantin.sch.id',
                'role' => 'student',
            ])
            ->assertSessionHasErrors('email');

        $this->assertDatabaseMissing('users', ['name' => 'Duplikat']);
    }

    // ------------------------------------------------------------------
    // Create — Kasir
    // ------------------------------------------------------------------

    public function test_admin_can_create_cashier_bound_to_console(): void
    {
        $console = $this->makeConsole(['name' => 'Kasir Utama']);

        $response = $this->actingAs($this->admin)->post(route('admin.users.store'), [
            'name' => 'Rina Kasir',
            'email' => 'rina@kantin.sch.id',
            'role' => 'cashier',
            'password' => 'kasir12345',
            'password_confirmation' => 'kasir12345',
            'device_id' => $console->device_id,
        ]);

        $user = User::where('email', 'rina@kantin.sch.id')->first();

        $this->assertNotNull($user);
        $response->assertRedirect(route('admin.users.edit', $user));

        $this->assertSame('cashier', $user->role);
        $this->assertSame($console->device_id, $user->device_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('kasir12345', $user->password));

        // Kredensial ditampilkan sekali.
        $response->assertSessionHas('credentials.device_id', $console->device_id);
        $response->assertSessionHas('credentials.password', 'kasir12345');
    }

    public function test_cashier_creation_requires_password(): void
    {
        $console = $this->makeConsole();

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Tanpa Sandi',
                'email' => 'nosandi@kantin.sch.id',
                'role' => 'cashier',
                'device_id' => $console->device_id,
            ])
            ->assertSessionHasErrors('password');

        $this->assertDatabaseMissing('users', ['email' => 'nosandi@kantin.sch.id']);
    }

    public function test_cashier_creation_rejects_console_already_used_by_another_cashier(): void
    {
        $console = $this->makeConsole();
        User::factory()->create([
            'role' => 'cashier',
            'device_id' => $console->device_id,
        ]);

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Rival',
                'email' => 'rival@kantin.sch.id',
                'role' => 'cashier',
                'password' => 'kasir12345',
                'password_confirmation' => 'kasir12345',
                'device_id' => $console->device_id,
            ])
            ->assertSessionHasErrors('device_id');

        $this->assertDatabaseMissing('users', ['email' => 'rival@kantin.sch.id']);
    }

    public function test_cashier_creation_rejects_inactive_console(): void
    {
        $console = $this->makeConsole(['is_active' => false]);

        $this->actingAs($this->admin)
            ->post(route('admin.users.store'), [
                'name' => 'Mati',
                'email' => 'mati@kantin.sch.id',
                'role' => 'cashier',
                'password' => 'kasir12345',
                'password_confirmation' => 'kasir12345',
                'device_id' => $console->device_id,
            ])
            ->assertSessionHasErrors('device_id');
    }

    // ------------------------------------------------------------------
    // Show
    // ------------------------------------------------------------------

    public function test_admin_can_view_user_detail(): void
    {
        $student = User::factory()->create(['name' => 'Detail Murid', 'role' => 'student']);

        $this->actingAs($this->admin)
            ->get(route('admin.users.show', $student))
            ->assertStatus(200)
            ->assertSee('Detail Murid')
            ->assertSee('Riwayat Transaksi');
    }

    // ------------------------------------------------------------------
    // Update
    // ------------------------------------------------------------------

    public function test_admin_can_update_student_profile(): void
    {
        $student = User::factory()->create([
            'name' => 'Nama Lama',
            'email' => 'lama@kantin.sch.id',
            'role' => 'student',
        ]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $student), [
                'name' => 'Nama Baru',
                'email' => 'baru@kantin.sch.id',
                'role' => 'student',
            ])
            ->assertRedirect(route('admin.users.edit', $student));

        $student->refresh();

        $this->assertSame('Nama Baru', $student->name);
        $this->assertSame('baru@kantin.sch.id', $student->email);
    }

    public function test_promoting_student_to_cashier_binds_console(): void
    {
        $console = $this->makeConsole();
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => $student->name,
            'email' => $student->email,
            'role' => 'cashier',
            'device_id' => $console->device_id,
            'password' => 'kasir12345',
            'password_confirmation' => 'kasir12345',
        ]);

        $student->refresh();

        $this->assertSame('cashier', $student->role);
        $this->assertSame($console->device_id, $student->device_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('kasir12345', $student->password));
    }

    public function test_demoting_cashier_to_student_releases_console(): void
    {
        $console = $this->makeConsole();
        $cashier = User::factory()->create([
            'role' => 'cashier',
            'device_id' => $console->device_id,
        ]);

        $this->actingAs($this->admin)->put(route('admin.users.update', $cashier), [
            'name' => $cashier->name,
            'email' => $cashier->email,
            'role' => 'student',
        ]);

        $this->assertNull($cashier->refresh()->device_id);
    }

    public function test_cashier_update_rejects_console_owned_by_another_cashier(): void
    {
        $consoleA = $this->makeConsole(['device_id' => 'ESP-111111111111']);
        $consoleB = $this->makeConsole(['device_id' => 'ESP-222222222222']);

        User::factory()->create(['role' => 'cashier', 'device_id' => $consoleB->device_id]);
        $cashierA = User::factory()->create(['role' => 'cashier', 'device_id' => $consoleA->device_id]);

        $this->actingAs($this->admin)
            ->put(route('admin.users.update', $cashierA), [
                'name' => $cashierA->name,
                'email' => $cashierA->email,
                'role' => 'cashier',
                'device_id' => $consoleB->device_id,
            ])
            ->assertSessionHasErrors('device_id');

        $this->assertSame($consoleA->device_id, $cashierA->refresh()->device_id);
    }

    public function test_blank_password_on_update_keeps_existing_password(): void
    {
        $student = User::factory()->create([
            'role' => 'student',
            'password' => bcrypt('password123'),
        ]);
        $before = $student->password;

        $this->actingAs($this->admin)->put(route('admin.users.update', $student), [
            'name' => 'Ganti Nama',
            'email' => $student->email,
            'role' => 'student',
            'password' => '',
        ]);

        $student->refresh();

        $this->assertSame($before, $student->password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('password123', $student->password));
    }

    // ------------------------------------------------------------------
    // Delete
    // ------------------------------------------------------------------

    public function test_admin_can_delete_student_without_transactions(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $student))
            ->assertRedirect(route('admin.users.index'));

        $this->assertModelMissing($student);
    }

    public function test_user_with_transactions_cannot_be_deleted(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $console = $this->makeConsole();

        $student->transactionsAsBuyer()->create([
            'console_id' => $console->id,
            'cashier_id' => $this->admin->id,
            'total_cents' => 15000,
            'status' => 'completed',
            'idempotency_key' => 'tx-'.\Illuminate\Support\Str::uuid(),
        ]);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $student))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($student);
    }

    public function test_admin_user_cannot_be_deleted(): void
    {
        $otherAdmin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($this->admin)
            ->delete(route('admin.users.destroy', $otherAdmin))
            ->assertSessionHasErrors('user');

        $this->assertModelExists($otherAdmin);
    }

    // ------------------------------------------------------------------
    // RFID
    // ------------------------------------------------------------------

    public function test_admin_can_assign_rfid_card(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.rfid.store', $student), [
                'rfid_uid' => 'a1b2c3d4',
                'label' => 'Kartu Utama',
            ])
            ->assertSessionHas('success');

        $card = RfidCard::where('rfid_uid', 'A1B2C3D4')->first();

        $this->assertNotNull($card);
        // UID harus ternormalisasi ke huruf besar.
        $this->assertSame('A1B2C3D4', $card->rfid_uid);
        $this->assertSame($student->id, $card->user_id);
        $this->assertTrue($card->is_active);
        $this->assertNull($card->revoked_at);
    }

    public function test_rfid_uid_must_be_eight_hex_characters(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        foreach (['XYZ12345', '1234567', '123456789', ''] as $badUid) {
            $this->actingAs($this->admin)
                ->post(route('admin.users.rfid.store', $student), ['rfid_uid' => $badUid])
                ->assertSessionHasErrors('rfid_uid');
        }

        $this->assertDatabaseCount('rfid_cards', 0);
    }

    public function test_rfid_uid_must_be_unique(): void
    {
        $first = User::factory()->create(['role' => 'student']);
        $second = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.rfid.store', $first), ['rfid_uid' => 'DEADBEEF']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.rfid.store', $second), ['rfid_uid' => 'DEADBEEF'])
            ->assertSessionHasErrors('rfid_uid');

        $this->assertDatabaseCount('rfid_cards', 1);
    }

    public function test_admin_can_revoke_rfid_card(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $card = $student->assignRfid('CAFEBABE');

        $this->actingAs($this->admin)
            ->delete(route('admin.users.rfid.destroy', [$student, $card]))
            ->assertSessionHas('success');

        $card->refresh();

        $this->assertFalse($card->is_active);
        $this->assertNotNull($card->revoked_at);
    }

    public function test_cannot_revoke_rfid_card_belonging_to_another_user(): void
    {
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $card = $owner->assignRfid('11223344');

        $this->actingAs($this->admin)
            ->delete(route('admin.users.rfid.destroy', [$other, $card]))
            ->assertNotFound();

        $this->assertTrue($card->refresh()->is_active);
    }

    public function test_revoked_uid_stays_reserved_and_cannot_be_reused(): void
    {
        // Kartu fisik yang dicabut masih bisa dipegang pihak lain, jadi UID
        // tidak boleh diberikan lagi ke user berbeda.
        $owner = User::factory()->create(['role' => 'student']);
        $other = User::factory()->create(['role' => 'student']);
        $card = $owner->assignRfid('55667788');

        $this->actingAs($this->admin)->delete(route('admin.users.rfid.destroy', [$owner, $card]));

        $this->actingAs($this->admin)
            ->post(route('admin.users.rfid.store', $other), ['rfid_uid' => '55667788'])
            ->assertSessionHasErrors('rfid_uid');

        $this->assertSame($owner->id, RfidCard::find($card->id)->user_id);
    }

    public function test_revoked_card_is_excluded_from_active_rfid_cards(): void
    {
        $student = User::factory()->create(['role' => 'student']);
        $keep = $student->assignRfid('11112222');
        $drop = $student->assignRfid('33334444');

        $this->actingAs($this->admin)->delete(route('admin.users.rfid.destroy', [$student, $drop]));

        $active = $student->activeRfidCards()->pluck('id')->all();

        $this->assertSame([$keep->id], $active);
    }

    public function test_user_can_have_multiple_active_rfid_cards(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)->post(route('admin.users.rfid.store', $student), ['rfid_uid' => 'AAAAAAAA']);
        $this->actingAs($this->admin)->post(route('admin.users.rfid.store', $student), ['rfid_uid' => 'BBBBBBBB']);

        $this->assertCount(2, $student->activeRfidCards()->get());
    }

    // ------------------------------------------------------------------
    // Saldo / Top-up
    // ------------------------------------------------------------------

    public function test_admin_can_top_up_student_balance(): void
    {
        $student = User::factory()->create(['role' => 'student', 'balance_cents' => 100000]);

        $this->actingAs($this->admin)
            ->post(route('admin.users.topup', $student), [
                'amount' => 5000,
                'description' => 'Setor tunai',
            ])
            ->assertSessionHas('success');

        // 100000 sen + 5000 rupiah (500000 sen)
        $this->assertSame(600000, $student->refresh()->balance_cents);
    }

    public function test_top_up_enforces_minimum_and_maximum(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)
            ->post(route('admin.users.topup', $student), ['amount' => 500])
            ->assertSessionHasErrors('amount');

        $this->actingAs($this->admin)
            ->post(route('admin.users.topup', $student), ['amount' => 60000000])
            ->assertSessionHasErrors('amount');

        $this->assertSame(0, $student->refresh()->balance_cents);
    }

    public function test_top_up_is_rejected_for_cashier_role(): void
    {
        $console = $this->makeConsole();
        $cashier = User::factory()->create(['role' => 'cashier', 'device_id' => $console->device_id]);

        $this->actingAs($this->admin)
            ->post(route('admin.users.topup', $cashier), ['amount' => 5000])
            ->assertSessionHasErrors('user');

        $this->assertSame(0, $cashier->refresh()->balance_cents);
    }

    public function test_top_up_records_audit_trail(): void
    {
        $student = User::factory()->create(['role' => 'student']);

        $this->actingAs($this->admin)->post(route('admin.users.topup', $student), [
            'amount' => 3000,
            'description' => 'Setor BANK',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $student->id,
            'action' => 'balance.topup',
        ]);
    }

    public function test_top_up_helper_rejects_non_positive_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        User::factory()->create(['role' => 'student'])->topUp(0);
    }
}