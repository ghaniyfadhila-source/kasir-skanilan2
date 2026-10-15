<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\PasswordReset;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class ForgotPasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => Hash::make('password123'),
            'role' => 'admin',
        ]);

        $this->student = User::factory()->create([
            'email' => 'budi@kantin.sch.id',
            'password' => Hash::make('lama12345'),
            'role' => 'student',
        ]);

        RateLimiter::clear('password-reset:generate:'.$this->student->id);
        RateLimiter::clear('password-reset:verify:127.0.0.1');
    }

    // ==================================================================
    // Model
    // ==================================================================

    public function test_generated_code_is_six_numeric_digits(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
    }

    public function test_code_is_never_stored_in_plain_text(): void
    {
        ['code' => $code, 'reset' => $reset] = PasswordReset::generateForUser($this->student);

        $this->assertNotSame($code, $reset->code_hash);
        $this->assertTrue(Hash::check($code, $reset->code_hash));
    }

    public function test_new_code_invalidates_previous_active_code(): void
    {
        ['code' => $first] = PasswordReset::generateForUser($this->student);
        ['code' => $second] = PasswordReset::generateForUser($this->student);

        $this->assertNotSame($first, $second);
        $this->assertFalse(PasswordReset::consumeFor($this->student, $first));
        $this->assertTrue(PasswordReset::consumeFor($this->student, $second));
    }

    public function test_expired_code_cannot_be_used(): void
    {
        ['code' => $code, 'reset' => $reset] = PasswordReset::generateForUser($this->student);
        $reset->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->assertFalse($reset->verifyCode($code));
        $this->assertFalse(PasswordReset::consumeFor($this->student, $code));
    }

    public function test_consumed_code_cannot_be_reused(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->assertTrue(PasswordReset::consumeFor($this->student, $code));
        $this->assertFalse(PasswordReset::consumeFor($this->student, $code), 'Kode hanya boleh sekali pakai');
    }

    public function test_code_of_another_user_is_rejected(): void
    {
        $other = User::factory()->create(['role' => 'student']);
        ['code' => $otherCode] = PasswordReset::generateForUser($other);

        $this->assertFalse(PasswordReset::consumeFor($this->student, $otherCode));
    }

    // ==================================================================
    // Admin membuat kode
    // ==================================================================

    public function test_admin_can_open_reset_page(): void
    {
        $this->actingAs($this->admin)
            ->get(route('admin.password-resets.create'))
            ->assertOk()
            ->assertSee('Reset Password')
            ->assertSee('budi@kantin.sch.id');
    }

    public function test_guest_cannot_open_admin_reset_page(): void
    {
        $this->get(route('admin.password-resets.create'))->assertRedirect(route('login'));
    }

    public function test_admin_can_generate_code_and_sees_it_once(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.password-resets.store'), ['user_id' => $this->student->id]);

        $response->assertRedirect(route('admin.password-resets.create'));
        $response->assertSessionHas('success');

        $code = session('reset_code.code');
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);

        $this->assertDatabaseHas('password_resets', [
            'user_id' => $this->student->id,
            'used_at' => null,
        ]);
    }

    public function test_code_is_audited_without_storing_the_code(): void
    {
        $response = $this->actingAs($this->admin)
            ->post(route('admin.password-resets.store'), ['user_id' => $this->student->id]);

        $code = session('reset_code.code');

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACT_PASSWORD_RESET_GENERATED,
            'user_id' => $this->student->id,
            'actor_id' => $this->admin->id,
        ]);

        // Kode polos hanya ada di flash, tidak boleh tersimpan di audit log.
        $auditJson = AuditLog::all()->toJson();

        $this->assertStringNotContainsString($code, $auditJson);
    }

    public function test_generating_code_is_rate_limited_to_three_per_hour(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($this->admin)
                ->post(route('admin.password-resets.store'), ['user_id' => $this->student->id])
                ->assertSessionHasNoErrors();
        }

        $this->actingAs($this->admin)
            ->post(route('admin.password-resets.store'), ['user_id' => $this->student->id])
            ->assertSessionHasErrors('user_id');
    }

    public function test_admin_can_cancel_active_codes(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->actingAs($this->admin)
            ->delete(route('admin.password-resets.destroy', $this->student))
            ->assertSessionHas('success');

        $this->assertFalse(PasswordReset::consumeFor($this->student, $code));
    }

    // ==================================================================
    // Form publik
    // ==================================================================

    public function test_public_reset_page_is_accessible_without_login(): void
    {
        $this->get(route('password.reset'))
            ->assertOk()
            ->assertSee('Reset Password')
            ->assertSee('name="code"', escape: false);
    }

    public function test_user_can_reset_password_with_valid_code(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => $code,
            'password' => 'barubaru123',
            'password_confirmation' => 'barubaru123',
        ])
            ->assertRedirect(route('admin.login'))
            ->assertSessionHas('success');

        $this->assertTrue(Hash::check('barubaru123', $this->student->fresh()->password));
    }

    public function test_reset_is_audited(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => $code,
            'password' => 'barubaru123',
            'password_confirmation' => 'barubaru123',
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'action' => AuditLogger::ACT_PASSWORD_RESET_USED,
            'user_id' => $this->student->id,
        ]);
    }

    public function test_same_code_cannot_be_used_twice(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => $code,
            'password' => 'pertama12345',
            'password_confirmation' => 'pertama12345',
        ])->assertSessionHasNoErrors();

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => $code,
            'password' => 'kedua12345',
            'password_confirmation' => 'kedua12345',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('pertama12345', $this->student->fresh()->password));
    }

    public function test_wrong_code_is_rejected(): void
    {
        PasswordReset::generateForUser($this->student);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => '999999',
            'password' => 'barubaru123',
            'password_confirmation' => 'barubaru123',
        ])->assertSessionHasErrors('code');

        $this->assertTrue(Hash::check('lama12345', $this->student->fresh()->password));
    }

    public function test_unknown_email_and_wrong_code_give_identical_message(): void
    {
        PasswordReset::generateForUser($this->student);

        // Email terdaftar, kode salah.
        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id', 'code' => '000000',
            'password' => 'barubaru123', 'password_confirmation' => 'barubaru123',
        ])->assertSessionHasErrors('code');
        $wrongCode = session('errors')->get('code')[0];

        // Email tidak terdaftar.
        $this->post(route('password.reset.verify'), [
            'email' => 'entah@kantin.sch.id', 'code' => '000000',
            'password' => 'barubaru123', 'password_confirmation' => 'barubaru123',
        ])->assertSessionHasErrors('code');
        $unknownEmail = session('errors')->get('code')[0];

        $this->assertSame(
            $wrongCode,
            $unknownEmail,
            'Pesan error harus sama supaya tidak bisa dipakai menebak email terdaftar'
        );
        $this->assertSame('Kode reset tidak valid atau sudah kadaluarsa.', $unknownEmail);
    }

    public function test_expired_code_is_rejected_on_public_form(): void
    {
        ['code' => $code, 'reset' => $reset] = PasswordReset::generateForUser($this->student);
        $reset->update(['expires_at' => Carbon::now()->subMinute()]);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id', 'code' => $code,
            'password' => 'barubaru123', 'password_confirmation' => 'barubaru123',
        ])->assertSessionHasErrors('code');
    }

    public function test_reset_form_validates_input_shape(): void
    {
        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id',
            'code' => 'abc',
            'password' => 'pendek',
            'password_confirmation' => 'lain',
        ])->assertSessionHasErrors(['code', 'password']);
    }

    public function test_reset_revokes_sanctum_tokens(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);
        $this->student->createToken('kasir-ESP-1');

        $this->assertDatabaseCount('personal_access_tokens', 1);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id', 'code' => $code,
            'password' => 'barubaru123', 'password_confirmation' => 'barubaru123',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseCount('personal_access_tokens', 0, connection: 'sqlite');
    }

    // ==================================================================
    public function test_login_page_shows_success_flash_after_reset(): void
    {
        // Kalau tidak dirender, user selesai reset tapi tidak diberi tahu apa pun.
        $this->withSession(['success' => 'Password berhasil diubah.'])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Password berhasil diubah.');
    }

    public function test_full_reset_journey_replaces_the_password(): void
    {
        ['code' => $code] = PasswordReset::generateForUser($this->student);

        $this->post(route('password.reset.verify'), [
            'email' => 'budi@kantin.sch.id', 'code' => $code,
            'password' => 'barubaru123', 'password_confirmation' => 'barubaru123',
        ])->assertRedirect(route('admin.login'));

        // Password lama harus ditolak.
        $this->post('/admin/login', [
            'email' => 'budi@kantin.sch.id', 'password' => 'lama12345',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            'Email atau password salah.',
            session('errors')->get('email')[0],
            'Password lama harus ditolak'
        );

        // Password baru harus diterima. Siswa memang tidak boleh masuk panel
        // admin, jadi yang dibuktikan adalah otentikasinya berhasil lalu
        // ditolak karena role, bukan karena password salah.
        $this->post('/admin/login', [
            'email' => 'budi@kantin.sch.id', 'password' => 'barubaru123',
        ])->assertSessionHasErrors('email');

        $this->assertSame(
            'Akses ditolak. Hanya admin yang dapat masuk.',
            session('errors')->get('email')[0],
            'Password baru harus bisa dipakai untuk autentikasi'
        );
    }

public function test_reset_password_unlocks_cashier_login(): void
    {
        // Alur nyata: kasir lupa password -> admin beri kode -> kasir login lagi.
        $console = Console::factory()->create(['is_active' => true]);
        ConsoleCredential::create([
            'console_id' => $console->id,
            'password_hash' => Hash::make('konsol123'),
            'api_key' => 'key-123',
        ]);

        $cashier = User::factory()->create([
            'email' => 'rina@kantin.sch.id',
            'password' => Hash::make('kasirlama1'),
            'role' => 'cashier',
            'device_id' => $console->device_id,
        ]);

        ['code' => $code] = PasswordReset::generateForUser($cashier);

        $this->post(route('password.reset.verify'), [
            'email' => 'rina@kantin.sch.id', 'code' => $code,
            'password' => 'kasibaru123', 'password_confirmation' => 'kasibaru123',
        ])->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('kasibaru123', $cashier->fresh()->password));

        // Login Cashier Web UI memakai password user, bukan password konsol.
        $this->postJson('/api/cashier/login', [
            'device_id' => $console->device_id,
            'password' => 'kasibaru123',
        ])->assertOk()->assertJsonPath('success', true);
    }

    // Prune
    // ==================================================================

    public function test_prune_removes_only_old_records(): void
    {
        $fresh = PasswordReset::generateForUser($this->student)['reset'];

        $old = PasswordReset::generateForUser($this->student)['reset'];
        $old->update(['used_at' => now()->subDays(3)]);

        $this->artisan('password-resets:prune --hours=24')->assertSuccessful();

        $this->assertDatabaseMissing('password_resets', ['id' => $old->id]);
        $this->assertDatabaseHas('password_resets', ['id' => $fresh->id]);
    }
}