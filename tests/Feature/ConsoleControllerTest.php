<?php

namespace Tests\Feature;

use App\Models\Console;
use App\Models\ConsoleCredential;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ConsoleControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create([
            'email' => 'admin@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'admin',
        ]);

        $this->cashier = User::factory()->create([
            'email' => 'cashier@kasir.local',
            'password' => bcrypt('password123'),
            'role' => 'cashier',
        ]);
    }

    public function test_admin_can_access_consoles_index(): void
    {
        Console::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)->get(route('admin.consoles.index'));

        $response->assertStatus(200);
        $response->assertSee('Kelola Console');
    }

    public function test_guest_cannot_access_consoles_index(): void
    {
        $response = $this->get(route('admin.consoles.index'));
        $response->assertRedirect(); // Redirects to /login which then redirects to /admin/login
    }

    public function test_cashier_cannot_access_consoles_index(): void
    {
        $response = $this->actingAs($this->cashier)->get(route('admin.consoles.index'));
        $response->assertStatus(403);
    }

    public function test_admin_can_access_create_console_page(): void
    {
        $response = $this->actingAs($this->admin)->get(route('admin.consoles.create'));

        $response->assertStatus(200);
        $response->assertSee('Tambah Console Baru');
    }

    public function test_admin_can_create_console(): void
    {
        $hexChars = '0123456789ABCDEF';
        $deviceId = 'ESP-';
        for ($i = 0; $i < 12; $i++) {
            $deviceId .= $hexChars[random_int(0, 15)];
        }

        $response = $this->actingAs($this->admin)->post(route('admin.consoles.store'), [
            'device_id' => $deviceId,
            'name' => 'Kasir Test',
            'location' => 'Lantai 1',
            'is_active' => true,
        ]);

        $console = Console::where('device_id', $deviceId)->first();
        $this->assertNotNull($console, "Console was not created. Response: " . $response->getContent());

        $expectedUrl = route('admin.consoles.edit', ['console' => $console->getRouteKey()]);
        $response->assertRedirect($expectedUrl);
        $response->assertSessionHas('success');

        $this->assertDatabaseHas('consoles', [
            'device_id' => $deviceId,
            'name' => 'Kasir Test',
            'location' => 'Lantai 1',
            'status' => 'offline',
            'is_active' => true,
        ]);

        // Check credentials created
        $this->assertDatabaseHas('console_credentials', [
            'console_id' => $console->id,
        ]);
    }

    public function test_admin_cannot_create_console_with_invalid_device_id(): void
    {
        $response = $this->actingAs($this->admin)->post(route('admin.consoles.store'), [
            'device_id' => 'INVALID-ID',
            'name' => 'Kasir Test',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_admin_cannot_create_console_with_duplicate_device_id(): void
    {
        $deviceId = 'ESP-' . strtoupper(\Illuminate\Support\Str::random(12));
        Console::factory()->create(['device_id' => $deviceId]);

        $response = $this->actingAs($this->admin)->post(route('admin.consoles.store'), [
            'device_id' => $deviceId,
            'name' => 'Kasir Test',
        ]);

        $response->assertSessionHasErrors('device_id');
    }

    public function test_admin_can_edit_console(): void
    {
        $console = Console::factory()->create([
            'name' => 'Old Name',
            'location' => 'Old Location',
            'status' => 'offline',
        ]);

        $response = $this->actingAs($this->admin)->get(route('admin.consoles.edit', $console));

        $response->assertStatus(200);
        $response->assertSee('Old Name');
    }

    public function test_admin_can_update_console(): void
    {
        $console = Console::factory()->create([
            'name' => 'Old Name',
            'status' => 'offline',
        ]);

        $response = $this->actingAs($this->admin)->put(route('admin.consoles.update', $console), [
            'name' => 'New Name',
            'location' => 'New Location',
            'status' => 'online',
            'is_active' => true,
        ]);

        $response->assertRedirect(route('admin.consoles.edit', $console));
        $response->assertSessionHas('success');

        $console->refresh();
        $this->assertEquals('New Name', $console->name);
        $this->assertEquals('New Location', $console->location);
        $this->assertEquals('online', $console->status);
    }

    public function test_admin_can_rotate_credentials(): void
    {
        $console = Console::factory()->create();
        $password = ConsoleCredential::generatePassword();
        $apiKey = ConsoleCredential::generateApiKey();

        ConsoleCredential::create([
            'console_id' => $console->id,
            'password_hash' => Hash::make($password),
            'api_key' => $apiKey,
        ]);

        $response = $this->actingAs($this->admin)->post(route('admin.consoles.rotate-credentials', $console));

        $response->assertRedirect(route('admin.consoles.edit', $console));
        $response->assertSessionHas('success');
        $response->assertSessionHas('rotated_credentials');

        $console->refresh();
        $credentials = $console->credentials;
        $this->assertNotEquals($apiKey, $credentials->api_key);
        $this->assertNotNull($credentials->rotated_at);
    }

    public function test_admin_can_delete_console_without_transactions(): void
    {
        $console = Console::factory()->create();

        $response = $this->actingAs($this->admin)->delete(route('admin.consoles.destroy', $console));

        $response->assertRedirect(route('admin.consoles.index'));
        $response->assertSessionHas('success');

        $this->assertDatabaseMissing('consoles', ['id' => $console->id]);
    }

    public function test_admin_cannot_delete_console_with_transactions(): void
    {
        $console = Console::factory()->create();
        $buyer = User::factory()->create(['role' => 'student', 'balance_cents' => 10000]);
        $cashier = User::factory()->create(['role' => 'cashier']);

        \App\Models\Transaction::factory()->create([
            'console_id' => $console->id,
            'user_id' => $buyer->id,
            'cashier_id' => $cashier->id,
            'total_cents' => 5000,
        ]);

        $response = $this->actingAs($this->admin)->delete(route('admin.consoles.destroy', $console));

        $response->assertSessionHasErrors('console');
        $this->assertDatabaseHas('consoles', ['id' => $console->id]);
    }

    public function test_console_generate_device_id(): void
    {
        $deviceId = Console::generateDeviceId();
        $this->assertTrue(Console::validateDeviceId($deviceId));
        $this->assertMatchesRegularExpression('/^ESP-[0-9A-F]{12}$/', $deviceId);
    }

    public function test_console_status_badge(): void
    {
        $console = Console::factory()->create(['status' => 'online']);
        $this->assertEquals('bg-green-100 text-green-800', $console->getStatusBadgeClass());
        $this->assertEquals('Online', $console->getStatusLabel());

        $console->status = 'offline';
        $this->assertEquals('bg-gray-100 text-gray-800', $console->getStatusBadgeClass());
        $this->assertEquals('Offline', $console->getStatusLabel());

        $console->status = 'maintenance';
        $this->assertEquals('bg-yellow-100 text-yellow-800', $console->getStatusBadgeClass());
        $this->assertEquals('Maintenance', $console->getStatusLabel());
    }

    public function test_credential_rotation_generates_new_values(): void
    {
        $password1 = ConsoleCredential::generatePassword();
        $apiKey1 = ConsoleCredential::generateApiKey();

        $credential = ConsoleCredential::create([
            'console_id' => Console::factory()->create()->id,
            'password_hash' => Hash::make($password1),
            'api_key' => $apiKey1,
        ]);

        $originalApiKey = $credential->api_key;

        $credential->rotateCredentials();
        $credential->refresh();

        $this->assertNotEquals($originalApiKey, $credential->api_key);
        $this->assertNotNull($credential->rotated_at);
    }
}